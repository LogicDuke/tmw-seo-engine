<?php
/**
 * Tests for keyword candidate classification audit.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }

require_once __DIR__ . '/../includes/keywords/class-keyword-candidate-classification-audit.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordCandidateClassificationAudit;

final class KeywordCandidateClassificationAuditTest extends TestCase {
    public function test_model_intent_commercial_category_phrases_are_flagged(): void {
        $cheapest = KeywordCandidateClassificationAudit::audit_row([
            'keyword' => 'cheapest sex cam sites',
            'intent_type' => 'model',
            'entity_type' => 'topic_entity',
        ]);
        $creative = KeywordCandidateClassificationAudit::audit_row([
            'keyword' => 'creative live cam chat hd',
            'intent_type' => 'model',
            'entity_type' => 'topic_entity',
        ]);

        $this->assertContains('misclassified_model_intent_candidate', $cheapest['reason_codes']);
        $this->assertContains('misclassified_model_intent_candidate', $creative['reason_codes']);
    }

    public function test_category_intent_standalone_person_name_is_flagged(): void {
        $row = KeywordCandidateClassificationAudit::audit_row([
            'keyword' => 'anisyia',
            'intent_type' => 'category',
            'entity_type' => 'topic_entity',
        ]);

        $this->assertContains('person_name_in_category_pool', $row['reason_codes']);
        $this->assertSame('review_model_pool', $row['recommended_review_action']);
    }

    public function test_video_intent_standalone_model_name_is_flagged(): void {
        $row = KeywordCandidateClassificationAudit::audit_row([
            'keyword' => 'anisyia',
            'intent_type' => 'video',
            'entity_type' => 'model',
        ]);

        $this->assertContains('standalone_model_name_in_video_pool', $row['reason_codes']);
        $this->assertSame('review_model_pool', $row['recommended_review_action']);
    }

    public function test_valid_model_platform_keyword_is_not_automatically_flagged_bad(): void {
        $row = KeywordCandidateClassificationAudit::audit_row([
            'keyword' => 'anisyia livejasmin',
            'intent_type' => 'model',
            'entity_type' => 'model',
        ]);

        $this->assertNotContains('misclassified_model_intent_candidate', $row['reason_codes']);
        $this->assertNotContains('person_name_in_category_pool', $row['reason_codes']);
        $this->assertNotContains('standalone_model_name_in_video_pool', $row['reason_codes']);
    }

    public function test_topic_entity_model_rows_are_flagged_for_manual_review(): void {
        $row = KeywordCandidateClassificationAudit::audit_row([
            'keyword' => 'anisyia livejasmin',
            'intent_type' => 'model',
            'entity_type' => 'topic_entity',
        ]);

        $this->assertContains('topic_entity_model_pool_review', $row['reason_codes']);
        $this->assertSame('keep_if_verified_model_keyword', $row['recommended_review_action']);
    }

    public function test_report_summary_counts_suspicious_rows(): void {
        $report = KeywordCandidateClassificationAudit::audit_rows([
            [ 'keyword' => 'cheapest sex cam sites', 'intent_type' => 'model', 'entity_type' => 'topic_entity' ],
            [ 'keyword' => 'anisyia', 'intent_type' => 'category', 'entity_type' => 'topic_entity' ],
            [ 'keyword' => 'anisyia', 'intent_type' => 'video', 'entity_type' => 'model' ],
            [ 'keyword' => 'anisyia livejasmin', 'intent_type' => 'model', 'entity_type' => 'model' ],
        ]);

        $this->assertSame(4, $report['summary']['total_scanned']);
        $this->assertSame(1, $report['summary']['suspicious_model_rows']);
        $this->assertSame(1, $report['summary']['suspicious_video_rows']);
        $this->assertSame(1, $report['summary']['suspicious_category_rows']);
        $this->assertSame(1, $report['summary']['rows_needing_manual_review']);
        $this->assertCount(3, $report['rows']);
    }

    public function test_audit_source_is_read_only_and_has_no_disallowed_write_calls(): void {
        $source = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-candidate-classification-audit.php');

        foreach ([ '$wpdb->update', '$wpdb->insert', '$wpdb->delete', 'update_post_meta(', 'wp_update_post(', 'wp_insert_post(', 'post_content =>', 'RankMathMapper', 'ajax_generate_now(', 'ContentEngine', 'update_option(' ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_admin_report_view_is_read_only(): void {
        $adminSource = (string) file_get_contents(__DIR__ . '/../includes/admin/class-admin.php');
        $method = $this->extractMethod($adminSource, 'render_keyword_candidate_classification_audit');

        $this->assertStringContainsString('Read-only:', $method);
        $this->assertStringContainsString('KeywordCandidateClassificationAudit::audit_database', $method);
        foreach ([ '$wpdb->update', '$wpdb->insert', '$wpdb->delete', 'update_post_meta(', 'wp_update_post(', 'wp_insert_post(', 'post_content =>', 'RankMathMapper', 'ajax_generate_now(', 'ContentEngine', 'update_option(' ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $method);
        }
    }

    private function extractMethod(string $source, string $method): string {
        $needle = 'function ' . $method . '(';
        $start = strpos($source, $needle);
        $this->assertIsInt($start, 'Method not found: ' . $method);
        $brace = strpos($source, '{', $start);
        $this->assertIsInt($brace, 'Method body not found: ' . $method);
        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            if ($source[$i] === '{') { $depth++; }
            if ($source[$i] === '}') { $depth--; }
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
        $this->fail('Method body was not closed: ' . $method);
    }
}
