<?php
namespace TMW\SEO\Lighthouse;

if (!defined('ABSPATH')) { exit; }

class Normalizer {
    public static function normalize(array $lighthouse_result): array {
        $categories = $lighthouse_result['categories'] ?? [];
        $audits = $lighthouse_result['audits'] ?? [];

        return [
            'lighthouse_version' => (string)($lighthouse_result['lighthouseVersion'] ?? 'unknown'),
            'performance_score' => isset($categories['performance']['score']) ? (float)$categories['performance']['score'] * 100 : null,
            'seo_score' => isset($categories['seo']['score']) ? (float)$categories['seo']['score'] * 100 : null,
            'lcp' => isset($audits['largest-contentful-paint']['numericValue']) ? (float)$audits['largest-contentful-paint']['numericValue'] : null,
            'cls' => isset($audits['cumulative-layout-shift']['numericValue']) ? (float)$audits['cumulative-layout-shift']['numericValue'] : null,
            'inp' => isset($audits['interaction-to-next-paint']['numericValue']) ? (float)$audits['interaction-to-next-paint']['numericValue'] : null,
        ];
    }
}
