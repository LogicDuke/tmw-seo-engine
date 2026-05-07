<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class DataForSEOPaidKeywordScanRunner {
    public static function run_for_post(int $post_id, bool $force_refresh = false): array {
        global $wpdb;
        if ($post_id <= 0) { return ['ok' => false, 'error' => 'invalid_post_id']; }
        if (!DataForSEO::is_configured()) { return ['ok' => false, 'error' => 'dataforseo_credentials_missing']; }

        $strategy = '\\TMWSEO\\Engine\\Keywords\\DataForSEOPageTypeKeywordStrategy';
        $context = $strategy::build_context_from_post($post_id);
        $plan = $strategy::build_preview_plan_for_post($post_id);
        if (!is_array($plan)) { return ['ok' => false, 'error' => 'plan_build_failed']; }

        $page_type = (string)($plan['page_type'] ?? 'unknown');
        $seeds = self::extract_seeds((array)($plan['seed_groups'] ?? []));
        $endpoints = array_values(array_unique(array_filter(array_map('strval', (array)($plan['recommended_endpoints'] ?? [])))));

        $runs = $wpdb->prefix . 'tmwseo_dfseo_scan_runs';
        $items_t = $wpdb->prefix . 'tmwseo_dfseo_scan_items';
        $now = current_time('mysql');
        $location_code = (string) DataForSEO::default_location_code();
        $language_code = (string) DataForSEO::default_language_code();

        $wpdb->insert($runs, [
            'post_id' => $post_id, 'page_type' => $page_type, 'status' => 'running',
            'location_code' => $location_code, 'language_code' => $language_code,
            'seed_count' => count($seeds), 'endpoint_count' => count($endpoints),
            'estimated_task_count' => count($seeds) * max(1, count($endpoints)), 'created_at' => $now,
        ]);
        $run_id = (int) $wpdb->insert_id;

        $counts = ['fetched'=>0,'filtered'=>0,'stored'=>0,'reused_fresh'=>0,'reused_stale'=>0,'skipped'=>0];
        foreach ($seeds as $seed) {
            foreach ($endpoints as $endpoint) {
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
                $items = (array)($res['items'] ?? []);
                foreach ($items as $row) {
                    $keyword = trim((string)($row['keyword'] ?? $row['keyword_data']['keyword'] ?? ''));
                    $counts['fetched']++;
                    $reason = self::filter_reason($keyword);
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

        $status = 'completed';
        $wpdb->update($runs, [
            'status'=>$status,'fetched_count'=>$counts['fetched'],'filtered_count'=>$counts['filtered'],'stored_count'=>$counts['stored'],
            'reused_fresh_count'=>$counts['reused_fresh'],'reused_stale_count'=>$counts['reused_stale'],'skipped_count'=>$counts['skipped'],'completed_at'=>current_time('mysql')
        ], ['id'=>$run_id]);

        return ['ok'=>true,'run_id'=>$run_id];
    }

    private static function extract_seeds(array $seed_groups): array { $out=[]; foreach ($seed_groups as $g) { foreach ((array)$g as $v) { if (is_string($v) && trim($v)!=='') $out[] = mb_strtolower(trim($v)); } } return array_values(array_unique($out)); }
    private static function latest_item_for(int $post_id,string $page_type,string $endpoint,string $seed): array { global $wpdb; $t=$wpdb->prefix.'tmwseo_dfseo_scan_items'; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE post_id=%d AND page_type=%s AND endpoint=%s AND seed=%s AND status IN ('stored') ORDER BY id DESC LIMIT 1",$post_id,$page_type,$endpoint,$seed),ARRAY_A); if(!$r)return[]; $r['freshness']=self::freshness((string)($r['fetched_at']?:$r['created_at'])); return $r; }
    private static function freshness(string $dt): string { $ts=strtotime($dt); if(!$ts)return 'unknown'; $days=(time()-$ts)/DAY_IN_SECONDS; if($days<=30)return 'fresh'; if($days<=90)return 'stale'; return 'old'; }
    private static function filter_reason(string $keyword): string { if($keyword==='') return 'empty_keyword'; if(preg_match('/\bpost format video\b/i',$keyword)) return 'technical_modifier'; if(preg_match('/\bfuck\b/i',$keyword)) return 'risky_term'; if(preg_match('/\b(\w+)\s+\1\b/i',$keyword)) return 'duplicate_self_repeat'; return ''; }
    private static function call_endpoint(string $endpoint,string $seed,string $location_code,string $language_code,array $context): array {
        switch ($endpoint) {
            case 'dataforseo_labs/google/keyword_ideas/live': return DataForSEO::keyword_ideas_live([$seed], (int)$location_code, $language_code, 50);
            case 'dataforseo_labs/google/keyword_overview/live': return DataForSEO::keyword_overview_live([$seed], (int)$location_code, $language_code, true);
            case 'dataforseo_labs/google/search_intent/live': return DataForSEO::search_intent_live([$seed], 'English');
            default: return ['ok'=>true,'items'=>[]];
        }
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
            'volume'=>isset($norm['keyword_info']['search_volume'])?(int)$norm['keyword_info']['search_volume']:null,
            'cpc'=>isset($norm['keyword_info']['cpc'])?(float)$norm['keyword_info']['cpc']:null,
            'competition'=>isset($norm['keyword_info']['competition'])?(float)$norm['keyword_info']['competition']:null,
            'intent'=>isset($norm['keyword_intent']['main_intent'])?(string)$norm['keyword_intent']['main_intent']:null,
            'raw_hash'=>$json?hash('sha256',$json):null,'raw_json'=>$json,'created_at'=>current_time('mysql')
        ]);
    }
}
