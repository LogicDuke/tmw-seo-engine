<?php
/**
 * Tests for TMW keyword pool metrics scoring.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolMetricsScorer;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-metrics-scorer.php';

final class KeywordPoolMetricsScorerTest extends TestCase {

    public function test_livejasmin_models_strong_metrics_are_phase_1_p1(): void {
        $score = $this->score('livejasmin models', 'model');

        $this->assertGreaterThanOrEqual(75, $score['tmw_score']);
        $this->assertSame('TMW-P1', $score['tmw_priority']);
        $this->assertSame('ready_for_phase_1_review', $score['tmw_indexing_readiness']);
        $this->assertSame('approve_for_phase_1', $score['tmw_recommended_action']);
    }

    public function test_webcam_models_strong_metrics_are_phase_1_p1(): void {
        $score = $this->score('webcam models', 'category');

        $this->assertSame('TMW-P1', $score['tmw_priority']);
        $this->assertSame('ready_for_phase_1_review', $score['tmw_indexing_readiness']);
    }

    public function test_asian_webcam_models_strong_metrics_are_phase_1_p1(): void {
        $score = $this->score('asian webcam models', 'category');

        $this->assertSame('TMW-P1', $score['tmw_priority']);
        $this->assertSame('ready_for_phase_1_review', $score['tmw_indexing_readiness']);
    }

    public function test_free_video_chat_archives_broad_non_tmw_intent(): void {
        $score = $this->score('free video chat', 'category', [ 'reason_codes' => [ 'archive_keyword' ], 'validation_state' => 'blocked', 'decision' => 'block' ]);

        $this->assertSame(0, $score['tmw_score']);
        $this->assertSame('TMW-Archive', $score['tmw_priority']);
        $this->assertSame('archive_do_not_use', $score['tmw_indexing_readiness']);
    }

    public function test_total_volume_footer_is_zero_archive(): void {
        $score = $this->score('Total Volume', 'category', [ 'reason_codes' => [ 'summary_or_footer_row' ], 'validation_state' => 'blocked', 'decision' => 'block' ]);

        $this->assertSame(0, $score['tmw_score']);
        $this->assertSame('TMW-Archive', $score['tmw_priority']);
        $this->assertSame('archive_do_not_use', $score['tmw_indexing_readiness']);
    }

    public function test_big_performers_defer_until_lj_50_model_milestone(): void {
        foreach ([ 'dani daniels', 'natasha nice' ] as $keyword) {
            $score = $this->score($keyword, 'model', [ 'model_name' => $keyword ]);
            $this->assertSame('defer_until_lj_50_model_milestone', $score['tmw_indexing_readiness']);
            $this->assertSame('defer_for_phase_2', $score['tmw_recommended_action']);
        }
    }

    public function test_video_standalone_person_name_is_not_phase_1_ready(): void {
        $score = $this->score('Lexy Ness', 'video', [ 'model_name' => 'Lexy Ness' ]);

        $this->assertNotSame('ready_for_phase_1_review', $score['tmw_indexing_readiness']);
        $this->assertNotSame('approve_for_phase_1', $score['tmw_recommended_action']);
    }

    public function test_model_name_webcam_video_can_be_phase_1_eligible(): void {
        $score = $this->score('Lexy Ness webcam video', 'video', [ 'model_name' => 'Lexy Ness' ]);

        $this->assertSame('TMW-P1', $score['tmw_priority']);
        $this->assertSame('ready_for_phase_1_review', $score['tmw_indexing_readiness']);
    }

    public function test_kwe_seo_score_without_cpc_still_scores_and_missing_cpc_does_not_fatal(): void {
        $score = $this->score('livejasmin models', 'model', [ 'cpc' => null, 'seo_score' => 75, 'is_golden_keyword' => false, 'kwe_opportunity_candidate' => true ]);

        $this->assertGreaterThan(0, $score['tmw_score']);
        $this->assertContains('kwe_opportunity_candidate', $score['tmw_reason_codes']);
    }

    public function test_currency_traffic_value_parses_as_high_commercial_signal(): void {
        $score = $this->score('webcam models', 'category', [ 'traffic_value' => '$53,102', 'cpc' => null ]);

        $this->assertSame('high', $score['tmw_commercial_band']);
        $this->assertContains('positive_traffic_value', $score['tmw_reason_codes']);
    }

    /** @param array<string,mixed> $overrides */
    private function score(string $keyword, string $pool, array $overrides = []): array {
        $row = array_merge([
            'keyword' => $keyword,
            'normalized_keyword' => strtolower($keyword),
            'volume' => 1200,
            'cpc' => 2.50,
            'competition' => 0.10,
            'seo_score' => 75,
            'traffic_value' => 840,
            'validation_state' => 'valid',
            'decision' => 'accept',
            'reason_codes' => [],
            'is_golden_keyword' => true,
            'kwe_opportunity_candidate' => true,
        ], $overrides);

        return (new KeywordPoolMetricsScorer())->score($row, $pool);
    }
}
