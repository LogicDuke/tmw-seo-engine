<?php
namespace TMWSEO\Engine\Opportunities;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class OpportunityEngine {
    /** @var string[] */
    private array $default_competitors = [
        'chaturbate.com',
        'stripchat.com',
        'camwhores.tv',
        'camcrawler.com',
    ];

    private KeywordGap $gap;
    private OpportunityScorer $scorer;
    private OpportunityDatabase $db;

    public function __construct(
        ?KeywordGap $gap = null,
        ?OpportunityScorer $scorer = null,
        ?OpportunityDatabase $db = null
    ) {
        $this->gap = $gap ?: new KeywordGap();
        $this->scorer = $scorer ?: new OpportunityScorer();
        $this->db = $db ?: new OpportunityDatabase();
    }

    /**
     * @param string[] $competitors
     * @return array<string,mixed>
     */
    public function run(array $competitors = []): array {
        $competitors = !empty($competitors) ? $competitors : $this->default_competitors;
        $known_map = $this->gap->known_keywords_map();

        $all_competitor_keywords = [];
        foreach ($competitors as $domain) {
            $res = DataForSEO::domain_organic_keywords((string) $domain, 500);
            if (empty($res['ok'])) {
                Logs::warn('opportunities', '[TMW-OPP] Competitor scan failed', [
                    'domain' => $domain,
                    'error' => $res['error'] ?? 'unknown',
                ]);
                continue;
            }

            foreach ((array) ($res['items'] ?? []) as $item) {
                $keyword = strtolower(trim((string) ($item['keyword'] ?? '')));
                if ($keyword === '') {
                    continue;
                }

                $all_competitor_keywords[] = [
                    'keyword' => $keyword,
                    'search_volume' => (int) ($item['keyword_info']['search_volume'] ?? $item['search_volume'] ?? 0),
                    'difficulty' => (float) ($item['keyword_properties']['keyword_difficulty'] ?? $item['keyword_difficulty'] ?? 0),
                    'competitor_url' => strtolower(trim((string) $domain)),
                    'model_relevance' => $this->estimate_model_relevance($keyword),
                ];
            }
        }

        $missing = $this->gap->detect_missing($all_competitor_keywords, $known_map);

        $scored = [];
        foreach ($missing as $row) {
            if (!$this->scorer->is_allowed_keyword((string) ($row['keyword'] ?? ''))) {
                continue;
            }

            $score = $this->scorer->score($row);
            if ($score <= 70) {
                continue;
            }

            $row['opportunity_score'] = $score;
            $scored[] = $row;
        }

        $stored = $this->db->store($scored);

        Logs::info('opportunities', '[TMW-OPP] Opportunity scan completed', [
            'competitors' => $competitors,
            'known_keywords' => count($known_map),
            'raw_count' => count($all_competitor_keywords),
            'missing_count' => count($missing),
            'qualified_count' => count($scored),
            'stored' => $stored,
        ]);

        return [
            'ok' => true,
            'stored' => $stored,
            'qualified_count' => count($scored),
            'missing_count' => count($missing),
        ];
    }

    private function estimate_model_relevance(string $keyword): float {
        $signals = [
            'cam', 'cams', 'webcam', 'live', 'chat', 'model', 'stream', 'adult',
        ];

        $keyword = strtolower($keyword);
        $matches = 0;

        foreach ($signals as $signal) {
            if (strpos($keyword, $signal) !== false) {
                $matches++;
            }
        }

        if ($matches === 0) {
            return 20.0;
        }

        return min(100.0, 45.0 + ($matches * 15.0));
    }
}
