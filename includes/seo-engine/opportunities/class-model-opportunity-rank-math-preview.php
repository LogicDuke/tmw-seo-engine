<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class ModelOpportunityRankMathPreview {
    public static function build(int $opportunity_id): array {
        global $wpdb;
        $opp_table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $kw_table = $wpdb->prefix . 'tmwseo_model_opportunity_keywords';
        $opp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$opp_table} WHERE id=%d", $opportunity_id), ARRAY_A);
        if (!is_array($opp)) {
            return ['focus_keyword' => '', 'supporting_keywords' => [], 'excluded_risky' => [], 'excluded_noise' => [], 'source_note' => 'Opportunity not found.'];
        }

        $keywords = (array) $wpdb->get_results($wpdb->prepare("SELECT keyword, role, volume, platform_detected FROM {$kw_table} WHERE opportunity_id=%d ORDER BY volume DESC, id ASC", $opportunity_id), ARRAY_A);
        $model_entity = trim((string) ($opp['model_entity'] ?? ''));
        $primary_keyword = trim((string) ($opp['primary_keyword'] ?? ''));
        $focus_keyword = self::pick_focus_keyword($model_entity, $primary_keyword, $keywords);

        $excluded_risky = [];
        $excluded_noise = [];
        $supporting = [];
        $seen = [];
        foreach (['rankmath_candidate', 'platform_intent', 'content_support'] as $role) {
            foreach ($keywords as $kw) {
                $kw_text = trim((string) ($kw['keyword'] ?? ''));
                if ($kw_text === '' || (string) ($kw['role'] ?? '') !== $role) { continue; }
                $norm = ModelOpportunityNormalizer::normalize_keyword($kw_text);
                if ($norm === '') { continue; }
                $kw_role = (string) ($kw['role'] ?? '');
                if (in_array($kw_role, ['risky_explicit', 'manual_review'], true)) { $excluded_risky[] = $kw_text; continue; }
                if ($kw_role === 'noise') { $excluded_noise[] = $kw_text; continue; }
                if ($norm === ModelOpportunityNormalizer::normalize_keyword($focus_keyword)) { continue; }
                if (isset($seen[$norm])) { continue; }
                $seen[$norm] = true;
                $supporting[] = $kw_text;
                if (count($supporting) >= 4) { break 2; }
            }
        }

        foreach ($keywords as $kw) {
            $kw_text = trim((string) ($kw['keyword'] ?? ''));
            if ($kw_text === '') { continue; }
            $kw_role = (string) ($kw['role'] ?? '');
            if ($kw_role === 'risky_explicit' || $kw_role === 'manual_review') { $excluded_risky[] = $kw_text; }
            if ($kw_role === 'noise') { $excluded_noise[] = $kw_text; }
        }

        return [
            'focus_keyword' => $focus_keyword,
            'supporting_keywords' => array_values(array_slice($supporting, 0, 4)),
            'excluded_risky' => array_values(array_unique($excluded_risky)),
            'excluded_noise' => array_values(array_unique($excluded_noise)),
            'source_note' => 'Preview only from imported opportunity keyword roles (rankmath_candidate → platform_intent → content_support), sorted by stored volume.',
        ];
    }

    private static function pick_focus_keyword(string $model_entity, string $primary_keyword, array $keywords): string {
        $entity_norm = ModelOpportunityNormalizer::normalize_keyword($model_entity);
        $primary_norm = ModelOpportunityNormalizer::normalize_keyword($primary_keyword);
        if ($entity_norm !== '' && $primary_norm === $entity_norm && self::is_focus_safe($primary_keyword, $keywords)) {
            return $primary_keyword;
        }
        if ($model_entity !== '' && self::is_focus_safe($model_entity, $keywords)) {
            return $model_entity;
        }
        if ($primary_keyword !== '' && self::is_focus_safe($primary_keyword, $keywords)) {
            return $primary_keyword;
        }
        return $model_entity !== '' ? $model_entity : $primary_keyword;
    }

    private static function is_focus_safe(string $keyword, array $rows): bool {
        $norm = ModelOpportunityNormalizer::normalize_keyword($keyword);
        if ($norm === '') { return false; }
        if (preg_match('/\b(porn|sex|xxx|nude|leaks?)\b/i', $norm)) { return false; }
        foreach ($rows as $row) {
            $row_norm = ModelOpportunityNormalizer::normalize_keyword((string) ($row['keyword'] ?? ''));
            if ($row_norm !== $norm) { continue; }
            if (in_array((string) ($row['role'] ?? ''), ['risky_explicit', 'noise', 'manual_review'], true)) { return false; }
        }
        return true;
    }
}
