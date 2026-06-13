<?php
/**
 * Tests for CategoryApprovedKeywordResolver.
 *
 * These are isolated unit tests that exercise resolver logic without a live
 * WordPress database. The DB-dependent resolve_for_category() path is covered
 * by the manual verification checklist in the PR-713 design doc.
 *
 * Run with: vendor/bin/phpunit tests/CategoryApprovedKeywordResolverTest.php
 *
 * @package TMWSEO\Engine\Keywords\Tests
 * @since   5.9.4
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords\Tests;

use PHPUnit\Framework\TestCase;

/**
 * We test the logic methods via a test-accessible subclass that exposes the
 * private helpers, allowing isolated unit testing without WordPress globals.
 */
class CategoryApprovedKeywordResolverTestable extends \TMWSEO\Engine\Keywords\CategoryApprovedKeywordResolver {

    /**
     * Expose process_rows for unit testing without a real DB.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function test_process_rows(
        array  $rows,
        string $focus_keyword,
        int    $rankmath_limit = 4,
        int    $content_limit  = 16,
        int    $post_id        = 1
    ): array {
        return $this->process_rows( $rows, $focus_keyword, $rankmath_limit, $content_limit, $post_id );
    }

    /** Expose normalise for testing. */
    public function test_normalise( string $keyword ): string {
        return $this->normalise( $keyword );
    }

    /** Expose token_key for testing. */
    public function test_token_key( string $keyword ): string {
        return $this->token_key( $keyword );
    }
}

class CategoryApprovedKeywordResolverTest extends TestCase {

    private CategoryApprovedKeywordResolverTestable $resolver;

    protected function setUp(): void {
        parent::setUp();
        $this->resolver = new CategoryApprovedKeywordResolverTestable();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function approved_row( string $keyword, int $volume = 0 ): array {
        return [ 'keyword' => $keyword, 'status' => 'approved', 'volume' => $volume ];
    }

    private function unapproved_row( string $keyword, string $status ): array {
        return [ 'keyword' => $keyword, 'status' => $status, 'volume' => 0 ];
    }

    // ── Normalisation tests ───────────────────────────────────────────────────

    public function test_normalise_lowercases_and_trims(): void {
        $this->assertSame( 'amateur cams', $this->resolver->test_normalise( '  Amateur Cams  ' ) );
    }

    public function test_normalise_collapses_whitespace(): void {
        $this->assertSame( 'live amateur sex', $this->resolver->test_normalise( 'live  amateur   sex' ) );
    }

    public function test_token_key_sorts_tokens(): void {
        $this->assertSame(
            $this->resolver->test_token_key( 'amateur webcam' ),
            $this->resolver->test_token_key( 'webcam amateur' )
        );
    }

    public function test_token_key_single_word(): void {
        $this->assertSame( 'amateur', $this->resolver->test_token_key( 'amateur' ) );
    }

    public function test_token_key_empty_string(): void {
        $this->assertSame( '', $this->resolver->test_token_key( '' ) );
    }

    // ── Status safety tests ───────────────────────────────────────────────────

    public function test_only_approved_rows_are_accepted(): void {
        $rows = [
            $this->approved_row( 'amateur webcam' ),
            $this->approved_row( 'amateur sex cams' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $this->assertCount( 2, array_merge( $result['rankmath_extras'], $result['content_terms'] ) );
    }

    public function test_queued_for_review_rows_are_skipped(): void {
        $rows = [
            $this->unapproved_row( 'amateur webcam sex', 'queued_for_review' ),
            $this->approved_row( 'amateur webcam' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertContains( 'amateur webcam', $all );
        $this->assertNotContains( 'amateur webcam sex', $all );
    }

    public function test_rejected_rows_are_skipped(): void {
        $rows = [
            $this->unapproved_row( 'amateur cam nude', 'rejected' ),
            $this->approved_row( 'amateur webcam' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertNotContains( 'amateur cam nude', $all );
    }

    public function test_ignored_rows_are_skipped(): void {
        $rows = [
            $this->unapproved_row( 'amateur naked cam', 'ignored' ),
            $this->approved_row( 'live amateur sex' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertNotContains( 'amateur naked cam', $all );
    }

    public function test_new_rows_are_skipped(): void {
        $rows = [
            $this->unapproved_row( 'amateur threesome cam', 'new' ),
            $this->approved_row( 'amateur webcam' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertNotContains( 'amateur threesome cam', $all );
    }

    // ── Deduplication tests ───────────────────────────────────────────────────

    public function test_focus_keyword_excluded_from_extras(): void {
        $rows = [
            $this->approved_row( 'Amateur Cams' ),   // same as focus
            $this->approved_row( 'amateur webcam' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertNotContains( 'Amateur Cams', $all );
        $this->assertContains( 'amateur webcam', $all );
    }

    public function test_case_insensitive_deduplication(): void {
        $rows = [
            $this->approved_row( 'Amateur Webcam' ),
            $this->approved_row( 'amateur webcam' ),    // exact dup lowercased
            $this->approved_row( 'amateur sex cams' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertCount( 2, $all, 'Expected 2 unique terms after case dedup' );
    }

    public function test_token_reordered_deduplication(): void {
        $rows = [
            $this->approved_row( 'amateur webcam' ),
            $this->approved_row( 'webcam amateur' ),   // reordered duplicate
            $this->approved_row( 'live amateur sex' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $all    = array_merge( $result['rankmath_extras'], $result['content_terms'] );
        $this->assertCount( 2, $all, 'Expected 2 unique terms after token-reorder dedup' );
        $this->assertNotContains( 'webcam amateur', $all );
    }

    // ── Bucketing tests ───────────────────────────────────────────────────────

    public function test_first_four_go_to_rankmath_extras(): void {
        $rows = array_map( fn($kw) => $this->approved_row( $kw ), [
            'amateur webcam',
            'amateur sex cams',
            'amateur webcam sex',
            'live amateur sex',
            'amateur anal cam',
            'big boobs amateur webcam',
        ] );
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams', 4, 16 );
        $this->assertCount( 4, $result['rankmath_extras'] );
        $this->assertSame( 'amateur webcam',    $result['rankmath_extras'][0] );
        $this->assertSame( 'amateur sex cams',  $result['rankmath_extras'][1] );
        $this->assertSame( 'amateur webcam sex', $result['rankmath_extras'][2] );
        $this->assertSame( 'live amateur sex',   $result['rankmath_extras'][3] );
    }

    public function test_remaining_go_to_content_terms(): void {
        $rows = array_map( fn($kw) => $this->approved_row( $kw ), [
            'amateur webcam',
            'amateur sex cams',
            'amateur webcam sex',
            'live amateur sex',
            'amateur anal cam',       // → content_terms
            'big boobs amateur webcam', // → content_terms
        ] );
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams', 4, 16 );
        $this->assertCount( 2, $result['content_terms'] );
        $this->assertSame( 'amateur anal cam', $result['content_terms'][0] );
        $this->assertSame( 'big boobs amateur webcam', $result['content_terms'][1] );
    }

    public function test_pool_count_reflects_total_accepted(): void {
        $rows = array_map( fn($kw) => $this->approved_row( $kw ), [
            'amateur webcam', 'amateur sex cams', 'live amateur sex',
        ] );
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams', 4, 16 );
        $this->assertSame( 3, $result['pool_count'] );
    }

    // ── Empty / edge cases ───────────────────────────────────────────────────

    public function test_no_approved_rows_returns_empty_buckets(): void {
        $rows = [
            $this->unapproved_row( 'amateur webcam', 'queued_for_review' ),
            $this->unapproved_row( 'amateur sex cams', 'rejected' ),
        ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $this->assertSame( [], $result['rankmath_extras'] );
        $this->assertSame( [], $result['content_terms'] );
        $this->assertSame( 0, $result['pool_count'] );
    }

    public function test_empty_rows_returns_empty_buckets(): void {
        $result = $this->resolver->test_process_rows( [], 'Amateur Cams' );
        $this->assertSame( [], $result['rankmath_extras'] );
        $this->assertSame( [], $result['content_terms'] );
        $this->assertSame( 0, $result['pool_count'] );
    }

    public function test_source_is_always_category_db_approved(): void {
        $rows   = [ $this->approved_row( 'amateur webcam' ) ];
        $result = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $this->assertSame( 'category_db_approved', $result['source'] );
    }

    public function test_skipped_unapproved_rows_are_recorded(): void {
        $rows = [
            $this->unapproved_row( 'amateur cam nude', 'queued_for_review' ),
            $this->approved_row( 'amateur webcam' ),
        ];
        $result  = $this->resolver->test_process_rows( $rows, 'Amateur Cams' );
        $skipped = $result['skipped'];
        $this->assertCount( 1, $skipped );
        $this->assertSame( 'amateur cam nude', $skipped[0]['term'] );
        $this->assertSame( 'queued_for_review', $skipped[0]['status'] );
        $this->assertSame( 'status_not_approved', $skipped[0]['reason'] );
    }

    public function test_rankmath_limit_is_respected(): void {
        $rows = array_map( fn($kw) => $this->approved_row( $kw ), [
            'term1', 'term2', 'term3', 'term4', 'term5', 'term6',
        ] );
        $result = $this->resolver->test_process_rows( $rows, 'focus', 2, 10 );
        $this->assertCount( 2, $result['rankmath_extras'] );
        $this->assertSame( 'term1', $result['rankmath_extras'][0] );
        $this->assertSame( 'term2', $result['rankmath_extras'][1] );
    }

    public function test_content_limit_is_respected(): void {
        $rows = array_map( fn($i) => $this->approved_row( "term{$i}" ), range( 1, 20 ) );
        $result = $this->resolver->test_process_rows( $rows, 'focus', 4, 5 );
        $this->assertCount( 5, $result['content_terms'] );
    }
}
