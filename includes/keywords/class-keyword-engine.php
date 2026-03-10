<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\KeywordIntelligence\KeywordDatabase;
use TMWSEO\Engine\KeywordIntelligence\KeywordClassifier;
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

        if (DataForSEO::is_over_budget()) {
            $budget = DataForSEO::get_monthly_budget_stats();
            Logs::warn('keywords', 'Discovery stopped: DataForSEO monthly API budget exceeded', [
                'budget_usd' => $budget['budget_usd'] ?? 0,
                'spent_usd' => $budget['spent_usd'] ?? 0,
                'remaining_usd' => $budget['remaining_usd'] ?? 0,
                'calls' => $budget['calls'] ?? 0,
            ]);
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
        $cluster_keyword_map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';

        $cluster_map_table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cluster_keyword_map_table)) === $cluster_keyword_map_table;
        if ($cluster_map_table_exists) {
            $wpdb->query("DELETE FROM {$cluster_keyword_map_table}");
        }

        QueryExpansionGraph::create_table();

        // 1) Collect seeds (adaptive, mix of base + your top categories).
        //    Mode 'import_only' skips discovery and only runs KD + clustering + pages.
        $inserted = 0;
        $discovered_total = 0;
        $accepted_total = 0;
        $seed_report = [
            'base_seeds' => 0,
            'static_seeds' => 0,
            'model_seeds' => 0,
            'tag_seeds' => 0,
            'video_seeds' => 0,
            'category_seeds' => 0,
            'competitor_seeds' => 0,
            'total_seeds' => 0,
        ];

        try {
            if ($mode !== 'import_only') {
                        $seed_bundle = self::collect_seeds((int) Settings::get('keyword_seeds_per_run', 300));
                        $seeds = $seed_bundle['seeds'];
                        $seed_report = $seed_bundle['counts'];
                        $max_seeds_per_run = (int) Settings::get('keyword_seed_batch_limit', 300);
                        $max_seeds_per_run = min(300, $max_seeds_per_run);
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
                        $seed_report['total_seeds'] = count($seeds);
                        Logs::info('keywords', '[TMW-KW] Seed source counts', $seed_report);
                        Logs::info('keywords', 'Seeds', ['count' => count($seeds), 'seeds' => array_slice($seeds, 0, 10)]);
                        $failures = 0;
                        $max_failures = 3;
                        $max_secondary_expansions = 200;
                        $secondary_expansions = 0;
                        $expanded_seeds = [];
                        $queue = [];

                        foreach ($seeds as $seed) {
                            $normalized_seed = KeywordValidator::normalize((string) $seed);
                            if ($normalized_seed === '' || isset($expanded_seeds[$normalized_seed])) {
                                continue;
                            }

                            $queue[] = [
                                'keyword' => $normalized_seed,
                                'depth' => 0,
                            ];
                            $expanded_seeds[$normalized_seed] = true;
                        }

                        for ($i = 0; $i < count($queue); $i++) {
                            if ($inserted >= $new_limit) {
                                break;
                            }

                            $node = $queue[$i];
                            $seed = (string) ($node['keyword'] ?? '');
                            $depth = (int) ($node['depth'] ?? 0);
                            if ($seed === '') {
                                continue;
                            }

                            if ($depth > 0 && QueryExpansionGraph::was_expanded_recently($seed, 30)) {
                                continue;
                            }

                            $res = self::fetch_seed_relationships($seed);
                            if (empty($res['ok'])) {
                                $failures++;
                                Logs::warn('keywords', 'DataForSEO failed', ['seed' => $seed]);
                                Logs::warn('keywords', 'DataForSEO keyword_suggestions/related_keywords failed', ['seed' => $seed, 'error' => $res['error'] ?? '']);

                                if (($res['error'] ?? '') === 'dataforseo_budget_exceeded') {
                                    Logs::warn('keywords', 'Discovery halted because DataForSEO API budget is exhausted', ['seed' => $seed]);
                                    break;
                                }

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

                            QueryExpansionGraph::mark_expanded($seed);
                            $failures = 0;

                            $items = (array) ($res['items'] ?? []);
                            $discovered_total += count($items);
                            $existing_map = [];
                            $lookup_keywords = [];
                            $relationships_created = 0;

                            foreach ($items as $it) {
                                if (!is_array($it)) {
                                    continue;
                                }
                                $kw = (string)($it['keyword'] ?? '');
                                if ($kw !== '') {
                                    $lookup_keywords[$kw] = true;
                                }

                                $relationship_type = (string) ($it['_tmw_relationship_type'] ?? 'suggestion');
                                if (QueryExpansionGraph::store_relationship($seed, $kw, $relationship_type)) {
                                    $relationships_created++;
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

                            $eligible_secondary = [];
                            foreach ($items as $it) {
                                if ($inserted >= $new_limit) break;
                                $kw = is_array($it) ? (string)($it['keyword'] ?? '') : '';
                                if ($kw === '') continue;
                                if (function_exists('tmw_seo_is_blocked_keyword') && tmw_seo_is_blocked_keyword($kw)) {
                                    continue;
                                }

                                $reason = null;
                                if (!KeywordValidator::is_relevant($kw, $reason)) {
                                    continue;
                                }
                                $accepted_total++;
            
                                $metrics = $it['keyword_info'] ?? [];
                                $vol = isset($metrics['search_volume']) ? (int)$metrics['search_volume'] : null;
                                $cpc = isset($metrics['cpc']) ? (float)$metrics['cpc'] : null;
                                $comp = isset($metrics['competition']) ? (float)$metrics['competition'] : null;
                                $difficulty = isset($metrics['keyword_difficulty']) ? (float) $metrics['keyword_difficulty'] : (float) ($it['keyword_difficulty'] ?? 0);
            
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
                                $classification = KeywordClassifier::classify($kw);
            
                                // skip very low volume early (still store raw)
                                if ($vol !== null && $vol < $min_volume) continue;
            
                                $existing = $existing_map[$kw] ?? null;
                                if ($existing) {
                                    // update sources
                                    $wpdb->query($wpdb->prepare(
                                        "UPDATE {$cand_table} SET sources = CONCAT(IFNULL(sources,''), %s), intent_type=%s, entity_type=%s, entity_id=%d, updated_at=%s WHERE id=%d",
                                        "\n" . 'dataforseo_suggest:' . $seed,
                                        (string) ($classification['intent_type'] ?? 'generic'),
                                        (string) ($classification['entity_type'] ?? 'generic'),
                                        (int) ($classification['entity_id'] ?? 0),
                                        current_time('mysql'),
                                        (int)$existing
                                    ));
                                } else {
                                    $wpdb->insert($cand_table, [
                                        'keyword' => $kw,
                                        'canonical' => $canonical,
                                        'status' => 'new',
                                        'intent' => $intent,
                                        'intent_type' => (string) ($classification['intent_type'] ?? 'generic'),
                                        'entity_type' => (string) ($classification['entity_type'] ?? 'generic'),
                                        'entity_id' => (int) ($classification['entity_id'] ?? 0),
                                        'volume' => $vol,
                                        'cpc' => $cpc,
                                        'difficulty' => null,
                                        'opportunity' => null,
                                        'sources' => 'dataforseo_suggest:' . $seed,
                                        'notes' => null,
                                        'updated_at' => current_time('mysql'),
                                    ], [
                                        '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s'
                                    ]);
            
                                    $inserted++;
                                }

                                if ($depth < 2 && $secondary_expansions < $max_secondary_expansions && $vol !== null && $vol >= 20 && $difficulty <= 50) {
                                    $candidate_seed = KeywordValidator::normalize($kw);
                                    if ($candidate_seed !== '' && !isset($expanded_seeds[$candidate_seed])) {
                                        $eligible_secondary[] = $candidate_seed;
                                    }
                                }
                            }

                            foreach ($eligible_secondary as $candidate_seed) {
                                if ($secondary_expansions >= $max_secondary_expansions) {
                                    break;
                                }
                                if (isset($expanded_seeds[$candidate_seed])) {
                                    continue;
                                }

                                $expanded_seeds[$candidate_seed] = true;
                                $queue[] = [
                                    'keyword' => $candidate_seed,
                                    'depth' => $depth + 1,
                                ];
                                $secondary_expansions++;
                            }

                            Logs::info('keywords', '[TMW-GRAPH] Query expansion node processed', [
                                'seed' => $seed,
                                'depth' => $depth,
                                'nodes_created' => count($lookup_keywords),
                                'relationships_created' => $relationships_created,
                                'secondary_expansions' => $secondary_expansions,
                            ]);

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

            $recent_metrics = KeywordDatabase::get_recent_metrics_map($to_score, 30);
            $to_refresh = [];
            $updated = 0;

            foreach ($to_score as $kw) {
                $cached = $recent_metrics[$kw] ?? null;
                if (is_array($cached)) {
                    $kd = (float) ($cached['difficulty'] ?? 0);
                    $vol = isset($kw_map[$kw]['volume']) && (int) $kw_map[$kw]['volume'] > 0
                        ? (int) $kw_map[$kw]['volume']
                        : (int) ($cached['search_volume'] ?? 0);
                    $intent = isset($kw_map[$kw]['intent']) ? (string) $kw_map[$kw]['intent'] : 'mixed';

                    $status = ($kd > $max_kd) ? 'rejected' : 'approved';
                    $opp = KDFilter::opportunity_score($kd, $vol, $intent);

                    $wpdb->update($cand_table, [
                        'difficulty' => $kd,
                        'opportunity' => $opp,
                        'status' => $status,
                        'notes' => ($kd > $max_kd) ? 'auto_reject:kD' : null,
                        'updated_at' => current_time('mysql'),
                    ], ['keyword' => $kw]);

                    $updated++;
                    continue;
                }

                $to_refresh[] = $kw;
            }

            Logs::info('keywords', '[TMW-KW] Reused keyword metrics cache', [
                'cached_keywords' => $updated,
                'refresh_keywords' => count($to_refresh),
            ]);

            if (!empty($to_refresh)) {
                $chunks = array_chunk($to_refresh, 100);
                $had_failures = false;

                foreach ($chunks as $chunk) {
                    $kd_res = DataForSEO::bulk_keyword_difficulty($chunk);
                    if (!$kd_res['ok']) {
                        $had_failures = true;
                        Logs::warn('keywords', '[TMW-KW] KD refresh batch failed', [
                            'error' => $kd_res['error'] ?? '',
                            'batch_size' => count($chunk),
                        ]);
                        continue;
                    }

                    $map = $kd_res['map'] ?? [];
                    foreach ($chunk as $kw) {
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

                        $classification = KeywordClassifier::classify($kw);

                        KeywordDatabase::upsert_metrics([
                            'keyword' => $kw,
                            'search_volume' => $vol,
                            'difficulty' => (float) $kd,
                            'serp_weakness' => 0,
                            'opportunity_score' => (float) $opp,
                            'source' => 'dataforseo',
                            'intent_type' => (string) ($classification['intent_type'] ?? 'generic'),
                            'entity_type' => (string) ($classification['entity_type'] ?? 'generic'),
                            'entity_id' => (int) ($classification['entity_id'] ?? 0),
                        ]);

                        $updated++;
                    }
                }

                Logs::info('keywords', '[TMW-KW] KD refreshed', [
                    'updated' => $updated,
                    'scored' => count($to_score),
                    'batched_requests' => count($chunks),
                ]);

                if ($had_failures) {
                    Logs::warn('keywords', '[TMW-KW] One or more KD batches failed', [
                        'batch_count' => count($chunks),
                    ]);
                }
            } else {
                Logs::info('keywords', '[TMW-KW] KD refresh skipped — all keywords recently checked');
            }
        }

        // 4) Build clusters (simple clustering by cluster_key)
        self::rebuild_clusters();
        $graph_stats = QueryExpansionGraph::generate_topic_clusters();
        Logs::info('keywords', '[TMW-GRAPH] Graph metrics persisted', $graph_stats);

        // 5) Suggestion-first workflow: queue suggested pages only (no auto-creation).
        self::store_suggested_pages_from_clusters($pages_per_day);
        self::store_topic_suggestions($pages_per_day);

        Logs::info('keywords', 'Keyword cycle completed');
        $summary_report = [
            'seeds_generated' => (int) ($seed_report['total_seeds'] ?? 0),
            'keywords_discovered' => (int) $discovered_total,
            'keywords_accepted' => (int) $accepted_total,
            'seed_breakdown' => $seed_report,
        ];
        update_option('tmw_keyword_last_discovery_report', $summary_report, false);
        Logs::info('keywords', '[TMW-KW] Discovery report', $summary_report);
        self::log_classification_counts();

    }



    private static function log_classification_counts(): void {
        global $wpdb;

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        $intent_counts = (array) $wpdb->get_results("SELECT intent_type, COUNT(*) as total FROM {$cand_table} GROUP BY intent_type", ARRAY_A);
        $entity_counts = (array) $wpdb->get_results("SELECT entity_type, COUNT(*) as total FROM {$cand_table} GROUP BY entity_type", ARRAY_A);

        Logs::info('keywords', '[TMW-KW] Intent classification counts', [
            'counts' => $intent_counts,
        ]);

        Logs::info('keywords', '[TMW-KW] Entity classification counts', [
            'counts' => $entity_counts,
        ]);
    }

    /**
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    private static function fetch_seed_relationships(string $seed): array {
        $cache_key = 'tmw_seed_suggestions_' . md5($seed);
        $cached = get_transient($cache_key);

        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $limit = (int) Settings::get('keyword_suggestions_limit', 200);
        $suggestions_res = DataForSEO::keyword_suggestions($seed, $limit);
        $related_res = DataForSEO::related_keywords($seed, 1, $limit);

        if (empty($suggestions_res['ok']) && empty($related_res['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($suggestions_res['error'] ?? $related_res['error'] ?? 'keyword_discovery_failed'),
            ];
        }

        $merged_items = [];
        foreach ([
            ['type' => 'suggestion', 'items' => (array) ($suggestions_res['items'] ?? [])],
            ['type' => 'related', 'items' => (array) ($related_res['items'] ?? [])],
        ] as $source) {
            foreach ($source['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $kw = (string) ($item['keyword'] ?? '');
                if ($kw === '') {
                    continue;
                }

                $item['_tmw_relationship_type'] = (string) $source['type'];
                $merged_items[KeywordValidator::normalize($kw)] = $item;
            }
        }

        $res = [
            'ok' => true,
            'items' => array_values($merged_items),
        ];

        set_transient($cache_key, $res, HOUR_IN_SECONDS);

        return $res;
    }

    private static function collect_seeds(int $limit): array {
        $static_seeds = [
            'adult webcam chat',
            'live cam girls',
            'webcam chat rooms',
            'adult video chat',
            'cam to cam chat',
            'random adult chat',
            'private cam show',
            'live adult chat',
        ];

        $source_counts = [
            'base_seeds' => count($static_seeds),
            'static_seeds' => 0,
            'model_seeds' => 0,
            'tag_seeds' => 0,
            'video_seeds' => 0,
            'category_seeds' => 0,
            'competitor_seeds' => 0,
            'total_seeds' => 0,
        ];

        foreach ($static_seeds as $seed) {
            if (SeedRegistry::register_seed($seed, 'static', 'system', 0)) {
                $source_counts['static_seeds']++;
            }
        }

        self::register_model_seeds($source_counts);
        self::register_tag_seeds($source_counts);
        self::register_video_seeds($source_counts);
        self::register_category_seeds($source_counts);
        self::register_competitor_seeds($source_counts);

        $orchestrated = DiscoveryOrchestrator::run(['source' => 'keyword_cycle']);
        $final_seeds = array_slice((array) ($orchestrated['seeds'] ?? []), 0, min(300, max(1, $limit)));

        $source_counts['total_seeds'] = count($final_seeds);

        return [
            'seeds' => $final_seeds,
            'counts' => $source_counts,
        ];
    }

    private static function register_model_seeds(array &$source_counts): void {
        $models = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (!is_array($models) || empty($models)) {
            return;
        }

        foreach ($models as $model_id) {
            $model_id = (int) $model_id;
            $model_name = trim((string) get_the_title($model_id));
            if ($model_name === '') {
                continue;
            }

            $variants = [
                $model_name . ' webcam',
                $model_name . ' live cam',
                $model_name . ' cam girl',
                $model_name . ' cam model',
                $model_name . ' webcam chat',
            ];

            foreach ($variants as $seed) {
                if (SeedRegistry::register_seed($seed, 'model', 'model', $model_id)) {
                    $source_counts['model_seeds']++;
                }
            }
        }
    }

    private static function register_tag_seeds(array &$source_counts): void {
        $terms = get_terms([
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'number' => 200,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        foreach ($terms as $term) {
            $name = trim((string) ($term->name ?? ''));
            if (mb_strlen($name, 'UTF-8') < 3) {
                continue;
            }

            $variants = [
                $name . ' cam girl',
                $name . ' webcam model',
                $name . ' live cam',
                $name . ' cam model',
            ];

            foreach ($variants as $seed) {
                if (SeedRegistry::register_seed($seed, 'tag', 'post_tag', (int) ($term->term_id ?? 0))) {
                    $source_counts['tag_seeds']++;
                }
            }
        }
    }

    private static function register_video_seeds(array &$source_counts): void {
        $videos = get_posts([
            'post_type' => 'video',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (!is_array($videos) || empty($videos)) {
            return;
        }

        foreach ($videos as $video_id) {
            $video_id = (int) $video_id;
            $title = trim((string) get_the_title($video_id));
            if ($title === '') {
                continue;
            }

            $variants = [
                $title . ' cam show',
                $title . ' live cam',
                $title . ' cam video',
            ];

            foreach ($variants as $seed) {
                if (SeedRegistry::register_seed($seed, 'video', 'video', $video_id)) {
                    $source_counts['video_seeds']++;
                }
            }
        }
    }

    private static function register_category_seeds(array &$source_counts): void {
        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'number' => 200,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        foreach ($terms as $term) {
            $name = trim((string) ($term->name ?? ''));
            if ($name === '') {
                continue;
            }

            $variants = [
                $name . ' cam girl',
                $name . ' webcam model',
                'best ' . $name . ' cam girls',
            ];

            foreach ($variants as $seed) {
                if (SeedRegistry::register_seed($seed, 'category', 'category', (int) ($term->term_id ?? 0))) {
                    $source_counts['category_seeds']++;
                }
            }
        }
    }

    private static function register_competitor_seeds(array &$source_counts): void {
        $competitors = Settings::competitor_domains();
        if (empty($competitors) || !DataForSEO::is_configured()) {
            return;
        }

        $rot = (int) get_option('tmwseo_engine_competitor_rot', 0);
        $domain = $competitors[$rot % count($competitors)];
        update_option('tmwseo_engine_competitor_rot', $rot + 1, false);

        $rk = DataForSEO::ranked_keywords($domain, 50);
        if (!$rk['ok']) {
            return;
        }

        foreach (($rk['items'] ?? []) as $it) {
            $kw = '';
            if (is_array($it) && isset($it['keyword_data']['keyword'])) {
                $kw = (string) $it['keyword_data']['keyword'];
            }

            if ($kw !== '' && SeedRegistry::register_seed($kw, 'competitor', 'domain', 0)) {
                $source_counts['competitor_seeds']++;
            }
        }
    }

    private static function rebuild_clusters(): void {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        $rows = $wpdb->get_results(
            "SELECT keyword, volume, difficulty, opportunity, intent, intent_type, entity_type, entity_id
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
            $entity_type = (string) ($r['entity_type'] ?? 'generic');
            $entity_id = (int) ($r['entity_id'] ?? 0);
            $intent_type = (string) ($r['intent_type'] ?? 'generic');
            if ($entity_type !== 'generic' && $entity_id > 0) {
                $key = sprintf('entity:%s:%d:%s', $entity_type, $entity_id, $intent_type);
            } else {
                $key = sprintf('%s:intent:%s', $key, $intent_type);
            }

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
            $normalized_keywords = array_values(array_unique(array_filter(array_map(static function ($keyword) {
                return KeywordValidator::normalize((string) $keyword);
            }, (array) ($c['keywords'] ?? [])))));

            $exists = $cluster_map[$key] ?? null;
            if ($exists) {
                $existing_cluster = (array) $wpdb->get_row($wpdb->prepare(
                    "SELECT id, page_id FROM {$cluster_table} WHERE id = %d LIMIT 1",
                    (int) $exists
                ), ARRAY_A);
                $existing_page_id = (int) ($existing_cluster['page_id'] ?? 0);

                $wpdb->update($cluster_table, [
                    'representative' => $c['best_kw'],
                    'keywords' => wp_json_encode($normalized_keywords),
                    'total_volume' => (int)$c['total_volume'],
                    'avg_difficulty' => $avg_kd,
                    'opportunity' => $opportunity,
                    'status' => $existing_page_id > 0 ? 'built' : 'new',
                    'updated_at' => current_time('mysql'),
                ], ['id' => (int)$exists]);

                if ($cluster_map_table_exists) {
                    foreach ($normalized_keywords as $keyword) {
                        $wpdb->insert(
                            $cluster_keyword_map_table,
                            [
                                'keyword' => $keyword,
                                'cluster_id' => (int) $exists,
                                'page_id' => $existing_page_id > 0 ? $existing_page_id : null,
                                'updated_at' => current_time('mysql'),
                            ],
                            ['%s', '%d', '%d', '%s']
                        );
                    }
                }
            } else {
                $wpdb->insert($cluster_table, [
                    'cluster_key' => $key,
                    'representative' => $c['best_kw'],
                    'keywords' => wp_json_encode($normalized_keywords),
                    'total_volume' => (int)$c['total_volume'],
                    'avg_difficulty' => $avg_kd,
                    'opportunity' => $opportunity,
                    'page_id' => null,
                    'status' => 'new',
                    'updated_at' => current_time('mysql'),
                ], [
                    '%s', '%s', '%s', '%d', '%f', '%f', '%d', '%s', '%s'
                ]);

                $new_cluster_id = (int) $wpdb->insert_id;
                if ($cluster_map_table_exists && $new_cluster_id > 0) {
                    foreach ($normalized_keywords as $keyword) {
                        $wpdb->insert(
                            $cluster_keyword_map_table,
                            [
                                'keyword' => $keyword,
                                'cluster_id' => $new_cluster_id,
                                'page_id' => null,
                                'updated_at' => current_time('mysql'),
                            ],
                            ['%s', '%d', '%d', '%s']
                        );
                    }
                }
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
             WHERE page_id IS NULL
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
