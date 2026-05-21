<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class ModelOpportunityImportService {
    private const KWS_COLUMNS = [
        'keyword' => 'keyword', 'volume' => 'volume', 'trend' => 'trend', 'trend dir.' => 'trend_dir',
        'seo score' => 'seo_score', 'traffic value' => 'traffic_value', 'competition' => 'competition',
        'ad difficulty' => 'ad_difficulty', 'lowest cpc' => 'lowest_cpc', 'average cpc' => 'average_cpc',
        'highest cpc' => 'highest_cpc', 'cpc spread' => 'cpc_spread',
    ];

    public static function import(int $import_id, string $mode, string $csv_path, array $context = [], bool $preview = false): array {
        $rows = self::parse_csv($csv_path);
        return self::apply_rows($import_id, $mode, $rows, $context, $preview);
    }

    public static function parse_csv(string $csv_path): array {
        $fh = fopen($csv_path, 'rb'); if (!$fh) return [];
        $header = null; $out = [];
        while (($line = fgetcsv($fh)) !== false) {
            $line = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $line);
            if ($header === null) {
                $norm = array_map(static fn($h) => ModelOpportunityNormalizer::normalize_keyword((string) $h), $line);
                $header = []; foreach ($norm as $i => $h) { if (isset(self::KWS_COLUMNS[$h])) $header[$i] = self::KWS_COLUMNS[$h]; }
                continue;
            }
            if (empty(array_filter($line, static fn($v) => $v !== null && $v !== ''))) continue;
            $row = ['raw' => $line];
            foreach ($header as $i => $k) { $row[$k] = $line[$i] ?? ''; }
            $out[] = $row;
        }
        fclose($fh);
        return $out;
    }

    public static function apply_rows(int $import_id, string $mode, array $rows, array $context = [], bool $preview = false): array {
        global $wpdb;
        $result = ['row_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'noise_count' => 0, 'failed_count' => 0, 'preview' => []];
        $model_map = self::build_model_map();
        $opp_table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $kw_table = $wpdb->prefix . 'tmwseo_model_opportunity_keywords';

        foreach ($rows as $row) {
            $result['row_count']++;
            $keyword = trim((string) ($row['keyword'] ?? ''));
            if ($keyword === '') continue;
            $is_total = str_starts_with(strtolower($keyword), 'total volume');

            $entity = self::resolve_entity($keyword, $mode, $context, $model_map, $is_total);
            if ($entity === '') {
                if (!$is_total) $result['noise_count']++;
                continue;
            }

            $canonical = ModelOpportunityNormalizer::canonical_entity_key($entity);
            $vol = (int) preg_replace('/[^0-9]/', '', (string) ($row['volume'] ?? '0'));

            if ($is_total) {
                if ($vol <= 0) continue;
                if ($preview) {
                    $result['preview'][] = ['entity' => $entity, 'opportunity_type' => self::opportunity_type($mode, 'content_support', $entity, $context), 'family_volume' => $vol, 'total_volume_row' => true];
                    continue;
                }
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$opp_table} WHERE canonical_entity_key=%s LIMIT 1", $canonical), ARRAY_A);
                $opportunity_type = self::opportunity_type($mode, 'content_support', $entity, $context);
                $payload = [
                    'canonical_entity_key' => $canonical,
                    'model_entity' => $entity,
                    'opportunity_type' => $opportunity_type,
                    'family_volume' => $vol,
                    'updated_at' => current_time('mysql'),
                ];
                if ($existing) {
                    $ok = $wpdb->update($opp_table, $payload, ['id' => (int) $existing['id']]);
                    if ($ok === false) { $result['failed_count']++; error_log('[TMW-MODEL-OPP] update_failed total_volume canonical=' . $canonical . ' error=' . $wpdb->last_error); continue; }
                    $result['updated_count']++;
                } else {
                    $payload['created_at'] = current_time('mysql');
                    $ok = $wpdb->insert($opp_table, $payload);
                    if ($ok === false || (int) $wpdb->insert_id <= 0) { $result['failed_count']++; error_log('[TMW-MODEL-OPP] insert_failed total_volume canonical=' . $canonical . ' error=' . $wpdb->last_error); continue; }
                    $result['created_count']++;
                }
                continue;
            }

            $role = ModelKeywordRoleClassifier::classify($keyword, $entity);
            $opportunity_type = self::opportunity_type($mode, $role, $entity, $context);
            $traffic = (float) preg_replace('/[^0-9.]/', '', (string) ($row['traffic_value'] ?? '0'));
            if ($preview) { $result['preview'][] = compact('keyword', 'entity', 'role', 'opportunity_type', 'vol', 'traffic'); continue; }
            if ($role === 'noise' || $opportunity_type === 'noise_archive') $result['noise_count']++;

            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$opp_table} WHERE canonical_entity_key=%s LIMIT 1", $canonical), ARRAY_A);
            $payload = [
                'canonical_entity_key' => $canonical, 'model_entity' => $entity, 'opportunity_type' => $opportunity_type,
                'primary_keyword' => $keyword, 'primary_volume' => $vol, 'traffic_value' => $traffic,
                'updated_at' => current_time('mysql'),
            ];
            $payload = array_merge($payload, ModelOpportunityScorer::score($payload));

            $opp_id = 0;
            if ($existing) {
                $ok = $wpdb->update($opp_table, $payload, ['id' => (int) $existing['id']]);
                if ($ok === false) { $result['failed_count']++; error_log('[TMW-MODEL-OPP] update_failed keyword canonical=' . $canonical . ' error=' . $wpdb->last_error); continue; }
                $opp_id = (int) $existing['id'];
                $result['updated_count']++;
            } else {
                $payload['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($opp_table, $payload);
                if ($ok === false || (int) $wpdb->insert_id <= 0) { $result['failed_count']++; error_log('[TMW-MODEL-OPP] insert_failed keyword canonical=' . $canonical . ' error=' . $wpdb->last_error); continue; }
                $opp_id = (int) $wpdb->insert_id;
                $result['created_count']++;
            }

            $kw_ok = $wpdb->insert($kw_table, [
                'opportunity_id' => $opp_id, 'import_id' => $import_id, 'keyword' => $keyword,
                'normalized_keyword' => ModelOpportunityNormalizer::normalize_keyword($keyword), 'role' => $role,
                'volume' => $vol, 'source' => ($context['source'] ?? null), 'competitor_domain' => ($context['competitor_domain'] ?? null),
                'platform_detected' => ($context['platform'] ?? null), 'raw_row_json' => wp_json_encode($row),
                'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
            ]);
            if ($kw_ok === false) {
                $result['failed_count']++;
                error_log('[TMW-MODEL-OPP] keyword_insert_failed canonical=' . $canonical . ' error=' . $wpdb->last_error);
            }
        }
        return $result;
    }

    private static function resolve_entity(string $keyword, string $mode, array $context, array $model_map, bool $is_total): string {
        $model = trim((string) ($context['model_entity'] ?? ''));
        if ($mode === 'kws_single_model_family' && $model !== '') {
            return self::match_model($model, $model_map) ?: ModelOpportunityNormalizer::normalize_model_name($model);
        }
        if ($is_total && $model === '') {
            return '';
        }
        if ($model === '') $model = self::extract_model_from_keyword($keyword);
        return self::match_model($model, $model_map) ?: ModelOpportunityNormalizer::normalize_model_name($model);
    }

    private static function build_model_map(): array {
        $posts = get_posts(['post_type' => 'model_bio', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
        $map = [];
        foreach ($posts as $id) {
            $title = (string) get_the_title($id); $slug = (string) get_post_field('post_name', $id);
            foreach ([$title, $slug, ModelOpportunityNormalizer::normalize_model_name($title), ModelOpportunityNormalizer::compact_name_key($title)] as $k) {
                $map[ModelOpportunityNormalizer::compact_name_key($k)] = $title;
            }
        }
        return $map;
    }
    private static function match_model(string $model, array $map): string { $k = ModelOpportunityNormalizer::compact_name_key($model); return $map[$k] ?? ''; }
    private static function extract_model_from_keyword(string $keyword): string { $parts = preg_split('/\b(porn|sex|xxx|nude|onlyfans|fansly|livejasmin|camsoda)\b/i', $keyword); return trim((string) ($parts[0] ?? $keyword)); }
    private static function opportunity_type(string $mode, string $role, string $entity, array $context): string {
        if ($role === 'noise') return 'noise_archive';
        if ($mode === 'kws_competitor_keywords') return 'competitor_gap';
        if ($mode === 'platform_model_list') return 'platform_coverage';
        if ($entity === '' || !empty($context['missing_model'])) return 'missing_model_acquisition';
        if ($role === 'primary' || $role === 'content_support') return 'existing_model_optimization';
        if ($role === 'platform_intent') return 'platform_coverage';
        return 'generic_keyword_candidate';
    }
}
