<?php
/**
 * SerpWeaknessEngine — evaluates SERP weakness for a keyword.
 *
 * v4.2: Now fetches real SERP data from DataForSEO before evaluating.
 * Falls back gracefully if DataForSEO is not configured.
 *
 * @since 4.2.0
 */
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class SerpWeaknessEngine {

    /**
     * Evaluates SERP weakness for a keyword.
     * Fetches real SERP data if DataForSEO is configured.
     *
     * @param array<int,array<string,mixed>> $serp_results  Optional pre-fetched SERP data.
     *                                                       If empty, will fetch via DataForSEO.
     */
    public function evaluate(string $keyword, array $serp_results = []): array {
        $started = microtime(true);

        // Fetch real SERP data if not provided
        if (empty($serp_results) && DataForSEO::is_configured()) {
            $res = DataForSEO::serp_live($keyword, 10);
            if (($res['ok'] ?? false) && !empty($res['items'])) {
                $serp_results = $res['items'];
            }
        }

        $signals = [
            'outdated' => 0, 'thin_content' => 0, 'low_authority' => 0,
            'ugc_pages' => 0, 'title_weakness' => 0, 'intent_mismatch' => 0,
            'low_depth' => 0, 'generic' => 0, 'weak_structured_content' => 0,
        ];

        $top = array_slice($serp_results, 0, 10);

        if (empty($top)) {
            // No SERP data available — return neutral score
            $result = [
                'keyword' => $keyword,
                'serp_weakness_score' => 5.0,
                'reason' => 'No SERP data available.',
                'signals' => $signals,
                'data_source' => 'none',
            ];
            $this->persist($result);
            return $result;
        }

        foreach ($top as $row) {
            $title         = strtolower((string) ($row['title'] ?? ''));
            $snippet       = strtolower((string) ($row['snippet'] ?? ''));
            $domain_rating = (float) ($row['domain_rating'] ?? 50);
            $age_days      = (int) ($row['age_days'] ?? 0);

            if ($age_days > 720)                                                              { $signals['outdated']++; }
            if ((int) ($row['word_count'] ?? 0) > 0 && (int) ($row['word_count'] ?? 0) < 900){ $signals['thin_content']++; }
            if ($domain_rating < 30)                                                          { $signals['low_authority']++; }
            if (preg_match('#(forum|quora|reddit|stackoverflow)#i', (string)($row['url']??''))) { $signals['ugc_pages']++; }
            if (strpos($title, strtolower($keyword)) === false)                               { $signals['title_weakness']++; }
            if (strpos($snippet, 'definition') !== false && strpos(strtolower($keyword), 'buy') !== false) { $signals['intent_mismatch']++; }
            if ((int) ($row['heading_count'] ?? 0) > 0 && (int) ($row['heading_count'] ?? 0) < 5) { $signals['low_depth']++; }
            if (!preg_match('/\b(ultimate|guide|comparison|best|top|review)\b/i', $title))    { $signals['generic']++; }
            if ((int) ($row['faq_count'] ?? 0) === 0)                                        { $signals['weak_structured_content']++; }
        }

        $score_10 = $this->calculate_score($signals, max(1, count($top)));
        $reason   = $this->explain($signals);

        $result = [
            'keyword'              => $keyword,
            'serp_weakness_score'  => $score_10,
            'reason'               => $reason,
            'signals'              => $signals,
            'serp_results_count'   => count($top),
            'data_source'          => DataForSEO::is_configured() ? 'dataforseo_live' : 'provided',
        ];

        $this->persist($result);

        Logs::info('intelligence', '[TMW-SERP] SERP weakness evaluated', [
            'keyword'     => $keyword,
            'score'       => $score_10,
            'source'      => $result['data_source'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        return $result;
    }

    private function calculate_score(array $signals, int $sample_size): float {
        $weights = [
            'outdated' => 0.14, 'thin_content' => 0.14, 'low_authority' => 0.12,
            'ugc_pages' => 0.12, 'title_weakness' => 0.10, 'intent_mismatch' => 0.13,
            'low_depth' => 0.10, 'generic' => 0.08, 'weak_structured_content' => 0.07,
        ];
        $weighted = 0.0;
        foreach ($weights as $key => $weight) {
            $ratio     = ((int) ($signals[$key] ?? 0)) / $sample_size;
            $weighted += $ratio * $weight;
        }
        return round(max(1.0, min(10.0, 1 + ($weighted * 9))), 2);
    }

    public function explain(array $signals): string {
        arsort($signals);
        $top = array_slice(array_keys($signals), 0, 3);
        return 'Top weakness signals: ' . implode(', ', array_map('strval', $top)) . '.';
    }

    private function persist(array $result): void {
        global $wpdb;
        $wpdb->insert(
            IntelligenceStorage::table_serp_analysis(),
            [
                'keyword'              => sanitize_text_field((string) $result['keyword']),
                'serp_weakness_score'  => (float) $result['serp_weakness_score'],
                'reason'               => sanitize_textarea_field((string) $result['reason']),
                'signals_json'         => wp_json_encode((array) $result['signals']),
                'created_at'           => current_time('mysql'),
            ],
            ['%s', '%f', '%s', '%s', '%s']
        );
    }
}
