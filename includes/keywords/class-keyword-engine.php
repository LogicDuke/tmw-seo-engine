<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Jobs;
use TMWSEO\Engine\Content\ContentEngine;
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

        $lock_key = 'tmw_dfseo_keyword_lock';

        if (get_transient($lock_key)) {
            Logs::warn('keywords', 'Seed processing skipped due to active lock');
            return;
        }

        set_transient($lock_key, 1, 120);

        $min_volume = (int) Settings::get('keyword_min_volume', 30);
        $max_kd     = (float) Settings::get('keyword_max_kd', 60);
        $new_limit  = (int) Settings::get('keyword_new_limit', 300);
        $kd_limit   = (int) Settings::get('keyword_kd_batch_limit', 300);
        $pages_per_day = (int) Settings::get('keyword_pages_per_day', 3);

        $raw_table = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $gen_table = $wpdb->prefix . 'tmw_generated_pages';

        // 1) Collect seeds (adaptive, mix of base + your top categories).
        //    Mode 'import_only' skips discovery and only runs KD + clustering + pages.
        $inserted = 0;

        try {
            if ($mode !== 'import_only') {
                        $seeds = self::collect_seeds((int) Settings::get('keyword_seeds_per_run', 5));
                        $max_seeds_per_run = (int) Settings::get('keyword_seed_batch_limit', 10);
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
                                $res = DataForSEO::keyword_suggestions(
                                    $seed,
                                    (int) Settings::get('keyword_suggestions_limit', 200)
                                );

                                if ($res['ok']) {
                                    set_transient($cache_key, $res, HOUR_IN_SECONDS);
                                }
                            }

                            if (!$res['ok']) {
                                $failures++;
                                Logs::warn('keywords', 'DataForSEO failed', ['seed' => $seed]);
                                Logs::warn('keywords', 'DataForSEO keyword_suggestions failed', ['seed' => $seed, 'error' => $res['error'] ?? '']);

                                if ($failures >= $max_failures) {
                                    Logs::error('keywords', 'Circuit breaker triggered');
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

        // 5) Auto-create a few pages for the best clusters (draft + noindex)
        self::auto_create_pages($pages_per_day);

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

    private static function auto_create_pages(int $limit): void {
        global $wpdb;
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $gen_table = $wpdb->prefix . 'tmw_generated_pages';

        if ($limit <= 0) return;

        $clusters = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$cluster_table}
             WHERE status='new' AND (page_id IS NULL OR page_id=0)
             ORDER BY opportunity DESC, total_volume DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($clusters)) {
            Logs::info('keywords', 'No clusters available for auto page creation');
            return;
        }

        $created = 0;
        foreach ($clusters as $c) {
            $keyword = (string)($c['representative'] ?? '');
            if ($keyword === '') continue;

            // Create a flat URL WordPress Page (NOT CPT) to match your chosen structure.
            $slug = sanitize_title($keyword);
            if ($slug === '') continue;

            // Ensure uniqueness (avoid collisions with existing posts/pages).
            $slug_base = $slug;
            $i = 2;
            while (get_page_by_path($slug, OBJECT, 'page')) {
                $slug = $slug_base . '-' . $i;
                $i++;
                if ($i > 20) break;
            }

            $wpdb->query('START TRANSACTION');

            try {
                $page_id = wp_insert_post([
                    'post_type' => 'page',
                    'post_status' => 'draft',
                    'post_title' => wp_strip_all_tags($keyword),
                    'post_name' => $slug,
                    'post_content' => "<!-- TMWSEO:AI -->\n",
                    'post_author' => 1,
                ], true);

                if (is_wp_error($page_id)) {
                    Logs::warn('keywords', 'Failed to create page', ['keyword' => $keyword, 'error' => $page_id->get_error_message()]);
                    throw new \RuntimeException($page_id->get_error_message());
                }

                // Mark as generated + map to cluster/keyword.
                update_post_meta($page_id, '_tmwseo_generated', 1);
                update_post_meta($page_id, '_tmwseo_cluster_id', (int)$c['id']);
                update_post_meta($page_id, '_tmwseo_keyword', $keyword);

                // Manual indexing workflow: default NOINDEX until you enable it.
                update_post_meta($page_id, 'rank_math_robots', ['noindex']);

                // Store in generated pages table
                $wpdb->insert($gen_table, [
                    'page_id' => (int)$page_id,
                    'cluster_id' => (int)$c['id'],
                    'keyword' => $keyword,
                    'kind' => 'keyword',
                    'indexing' => 'noindex',
                    'last_generated_at' => current_time('mysql'),
                ], [
                    '%d', '%d', '%s', '%s', '%s', '%s'
                ]);

                // Update cluster
                $wpdb->update($cluster_table, [
                    'page_id' => (int)$page_id,
                    'status' => 'built',
                    'updated_at' => current_time('mysql'),
                ], ['id' => (int)$c['id']]);

                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                if (!empty($page_id) && !is_wp_error($page_id)) {
                    wp_delete_post($page_id, true);
                }
                error_log('TMW Page Creation Failed: ' . $e->getMessage());
                continue;
            }

            // Enqueue content generation (800-1000 words, GPT-4o)
            Jobs::enqueue('optimize_post', 'page', (int)$page_id, [
                'context' => 'keyword_page',
                'keyword' => $keyword,
            ]);

            // Also log indexing candidate.
            self::log_indexing_candidate($page_id, $keyword);

            $created++;
        }

        Logs::info('keywords', 'Auto pages created', ['count' => $created]);
    }

    private static function log_indexing_candidate(int $page_id, string $keyword): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_indexing';

        $url = get_permalink($page_id);
        if (!$url) return;

        $wpdb->insert($table, [
            'url' => $url,
            'status' => 'candidate',
            'provider' => 'manual',
            'details' => wp_json_encode(['page_id' => $page_id, 'keyword' => $keyword]),
            'created_at' => current_time('mysql'),
        ], [
            '%s', '%s', '%s', '%s', '%s'
        ]);
    }
}
