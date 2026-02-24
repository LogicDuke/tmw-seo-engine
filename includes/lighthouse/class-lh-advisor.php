<?php
namespace TMW\SEO\Lighthouse;

if (!defined('ABSPATH')) { exit; }

class Advisor {
    public static function get_systemic_issues($strategy = 'mobile'): array {
        global $wpdb;
        $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';

        $targets = $wpdb->prefix . 'tmw_lighthouse_targets';
        $runs = $wpdb->prefix . 'tmw_lighthouse_runs';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.raw_json
             FROM {$targets} t
             INNER JOIN {$runs} r ON r.id = (
                SELECT rr.id FROM {$runs} rr
                WHERE rr.target_id = t.id AND rr.strategy = %s
                ORDER BY rr.created_at DESC
                LIMIT 1
             )",
            $strategy
        ), ARRAY_A) ?: [];

        $issues = [];

        foreach ($rows as $row) {
            $raw = json_decode((string)($row['raw_json'] ?? '{}'), true);
            $audits = $raw['audits'] ?? [];
            if (!is_array($audits)) {
                continue;
            }

            foreach ($audits as $audit_id => $audit) {
                if (!is_array($audit) || !array_key_exists('score', $audit)) {
                    continue;
                }

                $score = $audit['score'];
                if ($score === null || (float)$score >= 1.0) {
                    continue;
                }

                if (!isset($issues[$audit_id])) {
                    $issues[$audit_id] = [
                        'audit_id' => (string)$audit_id,
                        'title' => (string)($audit['title'] ?? $audit_id),
                        'description' => (string)($audit['description'] ?? ''),
                        'frequency' => 0,
                    ];
                }
                $issues[$audit_id]['frequency']++;
            }
        }

        usort($issues, static function ($a, $b) {
            return (int)$b['frequency'] <=> (int)$a['frequency'];
        });

        return array_values($issues);
    }
}
