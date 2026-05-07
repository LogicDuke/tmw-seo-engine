<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class DataForSEOPaidKeywordScanRunner {
    private const MANUAL_TASK_CAP = 25;
    private const MAX_RUN_SECONDS = 20;
    private const SMALL_TEST_MAX_SEEDS = 2;
    private const SMALL_TEST_ENDPOINT = 'dataforseo_labs/google/keyword_ideas/live';

    public static function run_for_post(int $post_id, bool $force_refresh = false, bool $small_test_only = false): array {
        global $wpdb;
        if ($post_id <= 0) { return ['ok' => false, 'error' => 'invalid_post_id']; }
        if (!DataForSEO::is_configured()) { return ['ok' => false, 'error' => 'dataforseo_credentials_missing']; }

        $strategy = '\\TMWSEO\\Engine\\Keywords\\DataForSEOPageTypeKeywordStrategy';
        $context = $strategy::build_context_from_post($post_id);
        $plan = $strategy::build_preview_plan_for_post($post_id);
        if (!is_array($plan)) { return ['ok' => false, 'error' => 'plan_build_failed']; }

        $page_type = (string)($plan['page_type'] ?? 'unknown');
        $seed_groups = (array)($plan['seed_groups'] ?? []);
        $seeds = self::extract_seeds($seed_groups);
        $endpoints = array_values(array_unique(array_filter(array_map('strval', (array)($plan['recommended_endpoints'] ?? [])))));
        $full_seed_count = count($seeds);
        $full_endpoint_count = count($endpoints);

        if ($small_test_only) {
            $seeds = self::select_small_test_seeds($seed_groups, self::SMALL_TEST_MAX_SEEDS);
            $endpoints = self::small_test_endpoints($endpoints);
        }

        $planned_tasks = count($seeds) * count($endpoints);

        $runs = $wpdb->prefix . 'tmwseo_dfseo_scan_runs';
        $items = $wpdb->prefix . 'tmwseo_dfseo_scan_items';
        if (!self::scan_ledger_tables_exist($runs, $items)) {
            return ['ok' => false, 'error' => 'scan_ledger_tables_missing'];
        }
        $now = current_time('mysql');
        $location_code = (string) DataForSEO::default_location_code();
        $language_code = (string) DataForSEO::default_language_code();

        $inserted = $wpdb->insert($runs, [
            'post_id' => $post_id, 'page_type' => $page_type, 'status' => 'running',
            'location_code' => $location_code, 'language_code' => $language_code,
            'seed_count' => count($seeds), 'endpoint_count' => count($endpoints),
            'estimated_task_count' => $planned_tasks, 'created_at' => $now,
        ]);
        $run_id = (int) $wpdb->insert_id;

        if ($inserted === false || $run_id <= 0) {
            $error = ['ok' => false, 'error' => 'scan_run_create_failed'];
            if (!empty($wpdb->last_error)) {
                $error['db_error'] = sanitize_text_field((string) $wpdb->last_error);
            }

            return $error;
        }

        if (!$small_test_only && $planned_tasks > self::MANUAL_TASK_CAP) {
            $wpdb->update($runs, [
                'status' => 'blocked_task_cap',
                'skipped_count' => $planned_tasks,
                'completed_at' => current_time('mysql'),
            ], ['id' => $run_id]);

            return [
                'ok' => false,
                'error' => 'task_cap_exceeded',
                'run_id' => $run_id,
                'seed_count' => count($seeds),
                'endpoint_count' => count($endpoints),
                'planned_tasks' => $planned_tasks,
                'max_tasks' => self::MANUAL_TASK_CAP,
                'full_seed_count' => $full_seed_count,
                'full_endpoint_count' => $full_endpoint_count,
            ];
        }

        $counts = ['fetched'=>0,'filtered'=>0,'stored'=>0,'reused_fresh'=>0,'reused_stale'=>0,'skipped'=>0];
        $status = 'completed';
        $started = time();
        foreach ($seeds as $seed) {
            foreach ($endpoints as $endpoint) {
                if ((time() - $started) > self::MAX_RUN_SECONDS) {
                    $status = 'partial_timeout';
                    $counts['skipped']++;
                    self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, '', 'skipped', 'time_budget_exceeded', 'unknown', [
                        'error' => 'time_budget_exceeded',
                    ]);
                    break 2;
                }

                $cached = self::latest_item_for($post_id, $page_type, $endpoint, $seed);
                if (!$force_refresh && !empty($cached) && ($cached['freshness'] ?? '') === 'fresh') {
                    $counts['reused_fresh']++;
                    self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, (string)($cached['keyword'] ?? $seed), 'reused_fresh', 'fresh_result_reused', 'fresh', null, $cached);
                    continue;
                }
                if (!empty($cached) && in_array(($cached['freshness'] ?? ''), ['stale','old'], true)) {
                    $counts['reused_stale']++;
                    self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, (string)($cached['keyword'] ?? $seed), 'reused_stale', null, (string)$cached['freshness'], null, $cached);
                }

                $res = self::call_endpoint($endpoint, $seed, $location_code, $language_code, $context);
                if (!($res['ok'] ?? false)) {
                    $counts['skipped']++;
                    self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, '', 'skipped', 'dataforseo_call_failed', 'unknown', [
                        'error' => (string)($res['error'] ?? 'unknown_error'),
                    ]);
                    continue;
                }
                $items = self::normalize_items_from_response($res);
                if (empty($items)) {
                    $counts['skipped']++;
                    self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, $seed, 'skipped', 'no_items_returned', 'unknown', [
                        'endpoint' => $endpoint,
                        'seed' => $seed,
                        'item_count' => 0,
                        'response_keys' => array_keys(is_array($res) ? $res : []),
                        'raw_keys' => array_keys(is_array($res['raw'] ?? null) ? $res['raw'] : []),
                        'task_keys' => self::extract_raw_task_result_keys($res),
                    ]);
                    continue;
                }
                foreach ($items as $row) {
                    $keyword = trim((string)($row['keyword'] ?? $row['keyword_data']['keyword'] ?? ''));
                    $counts['fetched']++;
                    $reason = self::filter_reason($keyword, $seed, $page_type, $context);
                    $freshness = 'fresh';
                    if ($reason !== '') {
                        $counts['filtered']++;
                        self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, $keyword, 'filtered', $reason, $freshness, $row);
                        continue;
                    }
                    $counts['stored']++;
                    self::insert_item($run_id, $post_id, $page_type, $endpoint, $seed, $keyword, 'stored', null, $freshness, $row);
                }
            }
        }

        if ($status === 'completed' && ($counts['skipped'] > 0 || $counts['filtered'] > 0)) {
            $status = 'completed_with_errors';
        }

        $wpdb->update($runs, [
            'status'=>$status,'fetched_count'=>$counts['fetched'],'filtered_count'=>$counts['filtered'],'stored_count'=>$counts['stored'],
            'reused_fresh_count'=>$counts['reused_fresh'],'reused_stale_count'=>$counts['reused_stale'],'skipped_count'=>$counts['skipped'],'completed_at'=>current_time('mysql')
        ], ['id'=>$run_id]);

        return ['ok'=>true,'run_id'=>$run_id,'status'=>$status];
    }

    private static function extract_seeds(array $seed_groups): array {
        $out = [];

        foreach ($seed_groups as $row) {
            if (!is_array($row)) {
                continue;
            }

            $seed = sanitize_text_field((string)($row['seed'] ?? ''));
            if ($seed === '') {
                continue;
            }

            $out[] = mb_strtolower(trim($seed));
        }

        return array_values(array_unique($out));
    }


    public static function preview_small_test_seeds(array $seed_groups, int $max = self::SMALL_TEST_MAX_SEEDS): array {
        return self::select_small_test_seeds($seed_groups, $max);
    }

    private static function select_small_test_seeds(array $seed_groups, int $max): array {
        if ($max <= 0) {
            return [];
        }

        $priority = [
            'name_modifier' => 10,
            'name_platform_modifier' => 20,
            'live_cam' => 30,
            'watch_live' => 40,
            'live_cam_schedule' => 50,
            'official_links' => 60,
            'name_platform' => 70,
            'handle_platform' => 80,
            'name_only' => 90,
        ];

        $ranked = [];
        foreach ($seed_groups as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $seed = mb_strtolower(trim(sanitize_text_field((string)($row['seed'] ?? ''))));
            if ($seed === '') {
                continue;
            }
            $group = sanitize_key((string)($row['group'] ?? $row['type'] ?? ''));
            $ranked[] = [
                'seed' => $seed,
                'priority' => $priority[$group] ?? 100,
                'group' => $group,
                'idx' => (int) $idx,
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            if ($a['priority'] === $b['priority']) {
                return $a['idx'] <=> $b['idx'];
            }
            return $a['priority'] <=> $b['priority'];
        });

        $selected = [];
        foreach ($ranked as $row) {
            if (isset($selected[$row['seed']])) {
                continue;
            }
            $selected[$row['seed']] = true;
            if (count($selected) >= $max) {
                break;
            }
        }

        return array_keys($selected);
    }

    private static function small_test_endpoints(array $endpoints): array {
        if (in_array(self::SMALL_TEST_ENDPOINT, $endpoints, true)) {
            return [self::SMALL_TEST_ENDPOINT];
        }

        return !empty($endpoints) ? [reset($endpoints)] : [];
    }

    private static function scan_ledger_tables_exist(string $runs_table, string $items_table): bool {
        global $wpdb;

        $has_runs = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $runs_table)) === $runs_table);
        $has_items = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items_table)) === $items_table);

        return $has_runs && $has_items;
    }

    private static function latest_item_for(int $post_id,string $page_type,string $endpoint,string $seed): array { global $wpdb; $t=$wpdb->prefix.'tmwseo_dfseo_scan_items'; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE post_id=%d AND page_type=%s AND endpoint=%s AND seed=%s AND status IN ('stored') ORDER BY id DESC LIMIT 1",$post_id,$page_type,$endpoint,$seed),ARRAY_A); if(!$r)return[]; $r['freshness']=self::freshness((string)($r['fetched_at']?:$r['created_at'])); return $r; }
    private static function freshness(string $dt): string { $ts=strtotime($dt); if(!$ts)return 'unknown'; $days=(time()-$ts)/DAY_IN_SECONDS; if($days<=30)return 'fresh'; if($days<=90)return 'stale'; return 'old'; }
    private static function filter_reason(string $keyword, string $seed, string $page_type, array $context): string {
        $keyword = mb_strtolower(trim($keyword));
        $seed = mb_strtolower(trim($seed));
        if ($keyword === '') { return 'empty_keyword'; }
        if (preg_match('/\bpost format video\b/i', $keyword)) { return 'technical_modifier'; }
        if (preg_match('/\bfuck\b/i', $keyword)) { return 'risky_term'; }
        if (preg_match('/\b(\w+)\s+\1\b/i', $keyword)) { return 'duplicate_self_repeat'; }

        if ($page_type !== 'model') {
            return '';
        }

        $entity_name = mb_strtolower(trim((string)($context['entity_name'] ?? '')));
        if ($entity_name === '') {
            return 'page_type_mismatch';
        }

        $has_entity_match = (mb_stripos($keyword, $entity_name) !== false);
        $seed_prefix = trim((string)preg_replace('/\s+(live cam|cam girls?|cam girl|models?)\b.*/i', '', $seed));
        $has_seed_phrase = ($seed_prefix !== '' && mb_stripos($keyword, $seed_prefix) !== false);

        if ($has_entity_match || $has_seed_phrase) {
            return '';
        }

        if (preg_match('/\b(live cam|webcam)\b/i', $keyword) && preg_match('/\b(padua|galveston|kenai|miami|vegas|tokyo|london)\b/i', $keyword)) {
            return 'generic_live_cam_location';
        }
        if (preg_match('/\b(leotards?|vanity|set|dress|fashion|ribbon|rainbow high)\b/i', $keyword)) {
            return 'product_or_fashion_mismatch';
        }
        if (preg_match('/\bgirls?\b/i', $keyword)) {
            return 'generic_girls_term';
        }
        if (preg_match('/\b(live cam|cam girls?|cam girl|models?|sexy|hot)\b/i', $keyword)) {
            return 'weak_seed_match';
        }

        return 'missing_entity_match';
    }
    private static function call_endpoint(string $endpoint,string $seed,string $location_code,string $language_code,array $context): array {
        switch ($endpoint) {
            case 'dataforseo_labs/google/keyword_ideas/live': return DataForSEO::keyword_ideas_live([$seed], (int)$location_code, $language_code, 50);
            case 'dataforseo_labs/google/keyword_overview/live': return DataForSEO::keyword_overview_live([$seed], (int)$location_code, $language_code, true);
            case 'dataforseo_labs/google/search_intent/live': return DataForSEO::search_intent_live([$seed], 'English');
            default: return ['ok'=>true,'items'=>[]];
        }
    }

    private static function normalize_items_from_response(array $res): array {
        $items = $res['items'] ?? null;
        if (is_array($items)) {
            return $items;
        }

        $raw = is_array($res['raw'] ?? null) ? $res['raw'] : [];
        $candidates = [
            $raw['tasks'][0]['result'][0]['items'] ?? null,
            $raw['tasks'][0]['result'][0]['keywords'] ?? null,
            $raw['tasks'][0]['result'] ?? null,
            $raw['result'][0]['items'] ?? null,
            $raw['result']['items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return array_values(array_filter($candidate, static function ($row) {
                    return is_array($row);
                }));
            }
        }

        return [];
    }


    private static function extract_raw_task_result_keys(array $res): array {
        $raw = is_array($res['raw'] ?? null) ? $res['raw'] : [];
        $result = $raw['tasks'][0]['result'][0] ?? $raw['tasks'][0]['result'] ?? $raw['result'][0] ?? $raw['result'] ?? null;
        return is_array($result) ? array_keys($result) : [];
    }

    private static function extract_volume(array $norm): ?int {
        $v = $norm['keyword_info']['search_volume'] ?? $norm['search_volume'] ?? $norm['volume'] ?? null;
        return is_numeric($v) ? (int) $v : null;
    }

    private static function extract_cpc(array $norm): ?float {
        $v = $norm['keyword_info']['cpc'] ?? $norm['cpc'] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }

    private static function extract_competition(array $norm): ?float {
        $v = $norm['keyword_info']['competition'] ?? $norm['competition'] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }

    private static function extract_intent(array $norm): ?string {
        $v = $norm['keyword_intent']['main_intent'] ?? $norm['main_intent'] ?? $norm['intent'] ?? null;
        return is_string($v) && $v != '' ? $v : null;
    }

    private static function insert_item(int $run_id,int $post_id,string $page_type,string $endpoint,string $seed,string $keyword,string $status,?string $reason,string $freshness,$row=null,array $cached=[]): void {
        global $wpdb; $t=$wpdb->prefix.'tmwseo_dfseo_scan_items'; $norm=is_array($row)?$row:[]; $json = !empty($norm) ? wp_json_encode($norm) : null;
        $fetched_at = current_time('mysql');
        $source_updated_at = null;
        if (in_array($status, ['reused_fresh', 'reused_stale'], true) && !empty($cached)) {
            $fetched_at = (string)($cached['fetched_at'] ?? $cached['created_at'] ?? $fetched_at);
            $source_updated_at = !empty($cached['source_updated_at']) ? (string)$cached['source_updated_at'] : null;
        }
        $wpdb->insert($t,[
            'run_id'=>$run_id,'post_id'=>$post_id,'page_type'=>$page_type,'endpoint'=>$endpoint,'seed'=>$seed,'keyword'=>substr($keyword,0,255),'source'=>'dataforseo','status'=>$status,
            'filter_reason'=>$reason,'freshness'=>$freshness,'fetched_at'=>$fetched_at,'source_updated_at'=>$source_updated_at,
            'volume'=>self::extract_volume($norm),
            'cpc'=>self::extract_cpc($norm),
            'competition'=>self::extract_competition($norm),
            'intent'=>self::extract_intent($norm),
            'raw_hash'=>$json?hash('sha256',$json):null,'raw_json'=>$json,'created_at'=>current_time('mysql')
        ]);
    }
}
