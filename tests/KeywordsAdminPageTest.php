<?php
/**
 * Tests for Keywords admin page pool views.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KeywordsAdminPageTest extends TestCase {
    private string $adminSource;
    private string $tableSource;

    protected function setUp(): void {
        $this->adminSource = (string) file_get_contents(__DIR__ . '/../includes/admin/class-admin.php');
        $this->tableSource = (string) file_get_contents(__DIR__ . '/../includes/admin/tables/class-keywords-table.php');
    }

    public function test_keywords_page_renders_pool_filters_and_existing_status_tabs(): void {
        foreach ([ 'All Pools', 'Model Keywords', 'Video Keywords', 'Category Keywords' ] as $label) {
            $this->assertStringContainsString($label, $this->adminSource);
        }

        foreach ([ 'All Candidates', 'New', 'Queued for Review', 'Approved', 'Ignored / Rejected', 'Raw Keywords', 'Keyword Clusters', 'Keyword Pool Classification Audit' ] as $label) {
            $this->assertStringContainsString($label, $this->adminSource);
        }
    }

    public function test_pool_filters_query_intent_type_for_category_video_and_model(): void {
        $this->assertStringContainsString("intent_type = %s", $this->tableSource);
        $this->assertStringContainsString("'intent_type' => 'category'", $this->adminSource);
        $this->assertStringContainsString("'intent_type' => 'video'", $this->adminSource);
        $this->assertStringContainsString("'intent_type' => 'model'", $this->adminSource);
        $this->assertStringContainsString("intent_type IN ('model','video','category')", $this->adminSource);
    }

    public function test_cleanup_controls_and_approved_protection_still_render(): void {
        $this->assertStringContainsString('Safe Keyword Cleanup', $this->adminSource);
        $this->assertStringContainsString('Preview Cleanup', $this->adminSource);
        $this->assertStringContainsString('Apply Cleanup', $this->adminSource);
        $this->assertStringContainsString('Approved keywords are never changed.', $this->adminSource);
        $this->assertStringContainsString('Protected because approved:', $this->adminSource);
    }

    public function test_notice_and_counts_render_for_saved_candidate_viewer(): void {
        $this->assertStringContainsString('Keyword candidates are stored in wp_tmw_keyword_candidates.', $this->adminSource);
        $this->assertStringContainsString('Approved Model', $this->adminSource);
        $this->assertStringContainsString('Approved Video', $this->adminSource);
        $this->assertStringContainsString('Approved Category', $this->adminSource);
    }

    public function test_candidate_columns_and_quick_filters_are_available_safely(): void {
        foreach ([ 'CPC', 'Competition', 'SEO Score', 'Opportunity Score', 'Traffic Value', 'Intent', 'Entity Type', 'Entity ID', 'Sources', 'Model Owner', 'Usage Scope', 'Primary?', 'Strategy', 'Recommended Action', 'Provenance', 'Entity Link Status', 'Updated' ] as $label) {
            $this->assertStringContainsString($label, $this->tableSource);
        }

        foreach ([ 'Approved Category Keywords', 'Approved Video Keywords', 'Approved Model Keywords', 'Queued Model Keywords', 'Queued Video Keywords', 'Queued Category Keywords', 'Personal Model CSV Keywords', 'Primary Model Bio Keywords', 'Unlinked Model Keywords', 'Ignored Model Keywords', 'High Volume + Low Competition', 'Golden / KWE Opportunity' ] as $label) {
            $this->assertStringContainsString($label, $this->adminSource);
        }
    }

    public function test_like_filter_patterns_are_escaped_and_tightly_match_primary_model_bio(): void {
        $this->assertStringContainsString('escaped_like_contains($wpdb, \'personal_model_keyword_csv\')', $this->tableSource);
        $this->assertStringContainsString('escaped_like_contains($wpdb, \'"model_keyword_primary_candidate":"yes"\')', $this->tableSource);
        $this->assertStringContainsString('escaped_like_contains($wpdb, \'"model_keyword_usage_scope":"model_bio_only"\')', $this->tableSource);
        $this->assertStringContainsString('$wpdb->esc_like($literal)', $this->tableSource);
        $this->assertStringContainsString('sources LIKE %s', $this->tableSource);
        $this->assertStringContainsString('notes LIKE %s', $this->tableSource);
    }

    public function test_keywords_table_reads_model_metadata_from_notes_or_sources_without_columns(): void {
        $this->assertStringContainsString('model_keyword_strategy_from_item', $this->tableSource);
        $this->assertStringContainsString('find_model_keyword_strategy', $this->tableSource);
        $this->assertStringContainsString('model_keyword_metadata_from_item', $this->tableSource);
        $this->assertStringContainsString('Unlinked model keyword', $this->tableSource);
        $this->assertStringContainsString('source_label_from_item', $this->tableSource);
        $this->assertStringContainsString("'notes'", $this->tableSource);
    }

    public function test_viewer_filters_do_not_write_rank_math_content_generate_or_indexing(): void {
        $renderKeywords = $this->extractMethod($this->adminSource, 'render_keywords');
        $keywordsTableMethods = $this->extractMethod($this->tableSource, 'prepare_items') . $this->extractMethod($this->tableSource, 'get_columns');
        $viewerSource = $renderKeywords . $keywordsTableMethods;

        foreach ([ 'RankMathMapper', 'update_post_meta(', 'wp_update_post(', 'wp_insert_post(', 'post_content', 'ajax_generate_now(', 'ContentEngine' ] as $forbiddenWrite) {
            $this->assertStringNotContainsString($forbiddenWrite, $viewerSource);
        }
    }

    public function test_pool_view_filters_do_not_add_disallowed_lifecycle_statuses(): void {
        $candidateViewBlock = $this->extractBetween($this->adminSource, '$status_map = [', '$quick_links[\'Reset Filters\']');

        foreach ([ 'pending', 'in_use', 'archived' ] as $status) {
            $this->assertStringNotContainsString($status, $candidateViewBlock);
        }

        foreach ([ 'new', 'scored', 'queued_for_review', 'approved', 'ignored' ] as $status) {
            $this->assertStringContainsString($status, $candidateViewBlock);
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

    private function extractBetween(string $source, string $startNeedle, string $endNeedle): string {
        $start = strpos($source, $startNeedle);
        $this->assertIsInt($start, 'Start needle not found: ' . $startNeedle);
        $end = strpos($source, $endNeedle, $start);
        $this->assertIsInt($end, 'End needle not found: ' . $endNeedle);
        return substr($source, $start, $end - $start);
    }
}
