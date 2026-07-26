<?php
/**
 * PR-B — scoped manual import-row rejection tests.
 *
 * Proves that rejecting one import row updates only that row: the globally
 * unique candidate row is never read or written, sibling rows in the same or
 * other batches/pools/targets are untouched, and batch counts update.
 *
 * Behavioral tests drive the REAL KeywordPoolImportBatchRepository against an
 * in-memory state-tracking $wpdb. The regression guard extracts the actual
 * Reject branch of KeywordPoolsAdminPage::handle_import_row_action() between
 * its [TMW-KW-SCOPED-REJECT] markers and scans that path itself — not a
 * helper — for forbidden candidate mutations and unsafe writes.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolImportBatchRepository;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php';

/**
 * In-memory wpdb: applies updates to seeded state and records every write
 * with its target table so tests can assert exactly which tables changed.
 */
final class ScopedRejectStateWpdb {
    public string $prefix = 'wp_';
    /** @var array<int,array<string,mixed>> */
    public array $candidates = [];
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    /** @var array<int,array<string,mixed>> */
    public array $batches = [];
    /** @var array<int,array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
    public array $writes = [];
    /** @var array<int,string> */
    public array $candidate_table_reads = [];

    public function prepare( string $sql, ...$args ): string {
        $i = 0;
        return (string) preg_replace_callback( '/%[sdf]/', function () use ( $args, &$i ) {
            $value = $args[ $i++ ] ?? '';
            return is_string( $value ) ? "'" . addslashes( $value ) . "'" : (string) $value;
        }, $sql );
    }

    public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }

    public function get_var( string $sql ) {
        $this->track_candidate_read( $sql );
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
            $clean = str_replace( '\\', '', $sql );
            foreach ( [ 'wp_tmw_keyword_import_batches', 'wp_tmw_keyword_import_rows', 'wp_tmw_keyword_candidates' ] as $table ) {
                if ( false !== stripos( $clean, $table ) ) { return $table; }
            }
        }
        return null;
    }

    public function get_row( string $sql, string $output = 'OBJECT' ) {
        $this->track_candidate_read( $sql );
        if ( false !== stripos( $sql, 'FROM wp_tmw_keyword_import_rows' ) && preg_match( '/COUNT\(\*\) AS total_rows/i', $sql ) ) {
            preg_match( '/batch_id\s*=\s*(\d+)/i', $sql, $m );
            $batch_id = (int) ( $m[1] ?? 0 );
            $counts = [ 'total_rows' => 0, 'inserted' => 0, 'updated' => 0, 'queued' => 0, 'review_required' => 0, 'approved' => 0, 'rejected' => 0, 'skipped' => 0, 'blocked' => 0, 'conflicts' => 0 ];
            foreach ( $this->rows as $row ) {
                if ( (int) ( $row['batch_id'] ?? 0 ) !== $batch_id ) { continue; }
                $counts['total_rows']++;
                $status = (string) ( $row['status'] ?? '' );
                if ( isset( $counts[ $status ] ) ) { $counts[ $status ]++; }
                if ( 'queued_for_review' === $status ) { $counts['queued']++; }
                $action = (string) ( $row['result_action'] ?? '' );
                if ( isset( $counts[ $action ] ) && in_array( $action, [ 'inserted', 'updated' ], true ) ) { $counts[ $action ]++; }
            }
            return $counts;
        }
        return null;
    }

    public function get_results( string $sql, string $output = 'OBJECT' ): array {
        $this->track_candidate_read( $sql );
        return [];
    }

    public function get_col( string $sql ): array { $this->track_candidate_read( $sql ); return []; }

    public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
        $this->writes[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        $id = (int) ( $where['id'] ?? 0 );
        if ( false !== stripos( $table, 'tmw_keyword_import_rows' ) && isset( $this->rows[ $id ] ) ) {
            $this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
            return 1;
        }
        if ( false !== stripos( $table, 'tmw_keyword_import_batches' ) && isset( $this->batches[ $id ] ) ) {
            $this->batches[ $id ] = array_merge( $this->batches[ $id ], $data );
            return 1;
        }
        if ( false !== stripos( $table, 'tmw_keyword_candidates' ) && isset( $this->candidates[ $id ] ) ) {
            $this->candidates[ $id ] = array_merge( $this->candidates[ $id ], $data );
            return 1;
        }
        return 0;
    }

    public function insert( ...$args ) { $this->writes[] = [ 'table' => 'INSERT', 'data' => [], 'where' => [] ]; return false; }
    public function delete( ...$args ) { $this->writes[] = [ 'table' => 'DELETE', 'data' => [], 'where' => [] ]; return false; }
    public function query( string $sql ) { $this->writes[] = [ 'table' => 'QUERY:' . $sql, 'data' => [], 'where' => [] ]; return 0; }

    private function track_candidate_read( string $sql ): void {
        if ( false !== stripos( str_replace( '\\', '', $sql ), 'tmw_keyword_candidates' ) && false === stripos( $sql, 'SHOW TABLES' ) ) {
            $this->candidate_table_reads[] = $sql;
        }
    }
}

final class KeywordPoolScopedRejectTest extends TestCase {

    private $original_wpdb;
    private static string $admin_source = '';
    private static string $reject_branch = '';

    public static function setUpBeforeClass(): void {
        self::$admin_source = (string) file_get_contents( __DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php' );
        $start = strpos( self::$admin_source, '[TMW-KW-SCOPED-REJECT] begin row-only rejection' );
        $end   = strpos( self::$admin_source, '[TMW-KW-SCOPED-REJECT] end row-only rejection' );
        self::$reject_branch = ( false !== $start && false !== $end && $end > $start )
            ? substr( self::$admin_source, $start, $end - $start )
            : '';
    }

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ── Fixtures (generic data only) ──────────────────────────────────────

    private function wpdb(): ScopedRejectStateWpdb {
        $wpdb = new ScopedRejectStateWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    /**
     * Replays the exact write the scoped Reject path performs for a row.
     */
    private function scoped_reject( KeywordPoolImportBatchRepository $repository, int $row_id ): bool {
        return $repository->update_import_row( $row_id, [
            'status'        => 'rejected',
            'result_action' => 'rejected',
            'result_reason' => 'manually_rejected_row_only',
            'reviewed_by'   => 7,
            'reviewed_at'   => '2026-07-26 12:00:00',
        ] );
    }

    private function row( int $id, int $batch_id, string $keyword, int $candidate_id, string $status = 'queued_for_review' ): array {
        return [ 'id' => $id, 'batch_id' => $batch_id, 'keyword' => $keyword, 'normalized_keyword' => $keyword, 'candidate_id' => $candidate_id, 'status' => $status, 'result_action' => 'inserted', 'result_reason' => '' ];
    }

    private function batch( int $id, string $pool, int $target_id, string $target_name ): array {
        return [ 'id' => $id, 'pool' => $pool, 'target_type' => 'tmw_category_page', 'target_id' => $target_id, 'target_name' => $target_name, 'rejected' => 0, 'approved' => 0 ];
    }

    /** Assert no write ever targeted the candidates table and no candidate read occurred. */
    private function assertCandidateTableUntouched( ScopedRejectStateWpdb $wpdb ): void {
        foreach ( $wpdb->writes as $write ) {
            $this->assertFalse(
                stripos( $write['table'], 'tmw_keyword_candidates' ),
                'A write targeted the candidates table during scoped rejection.'
            );
        }
        $this->assertSame( [], $wpdb->candidate_table_reads, 'The candidates table must not even be read during scoped rejection.' );
    }

    // ── 1. Shared candidate across two batches ────────────────────────────

    public function test_reject_shared_candidate_row_leaves_candidate_and_other_batch_untouched(): void {
        $wpdb = $this->wpdb();
        $wpdb->candidates = [ 42 => [ 'id' => 42, 'keyword' => 'alpha shared phrase', 'status' => 'approved' ] ];
        $wpdb->batches    = [ 1 => $this->batch( 1, 'category', 501, 'Target A' ), 2 => $this->batch( 2, 'category', 501, 'Target A' ) ];
        $wpdb->rows       = [
            100 => $this->row( 100, 1, 'alpha shared phrase', 42 ),
            101 => $this->row( 101, 2, 'alpha shared phrase', 42, 'approved' ),
        ];

        $this->assertTrue( $this->scoped_reject( new KeywordPoolImportBatchRepository(), 100 ) );

        $this->assertSame( 'rejected', $wpdb->rows[100]['status'] );
        $this->assertSame( 'manually_rejected_row_only', $wpdb->rows[100]['result_reason'] );
        $this->assertSame( 'approved', $wpdb->rows[101]['status'], 'Row in the other batch must be untouched.' );
        $this->assertSame( 'approved', $wpdb->candidates[42]['status'], 'Global candidate status must be unchanged.' );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    // ── 2. Same candidate under two targets ───────────────────────────────

    public function test_reject_under_target_a_does_not_affect_target_b(): void {
        $wpdb = $this->wpdb();
        $wpdb->candidates = [ 43 => [ 'id' => 43, 'keyword' => 'beta shared phrase', 'status' => 'approved' ] ];
        $wpdb->batches    = [ 3 => $this->batch( 3, 'category', 601, 'Target A' ), 4 => $this->batch( 4, 'category', 602, 'Target B' ) ];
        $wpdb->rows       = [
            110 => $this->row( 110, 3, 'beta shared phrase', 43 ),
            111 => $this->row( 111, 4, 'beta shared phrase', 43, 'approved' ),
        ];

        $this->scoped_reject( new KeywordPoolImportBatchRepository(), 110 );

        $this->assertSame( 'rejected', $wpdb->rows[110]['status'] );
        $this->assertSame( 'approved', $wpdb->rows[111]['status'], 'Target B row must be untouched.' );
        $this->assertSame( 'approved', $wpdb->candidates[43]['status'] );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    // ── 3 & 4. Cross-pool independence (both directions) ──────────────────

    public function test_rejecting_category_row_does_not_affect_model_pool_row(): void {
        $wpdb = $this->wpdb();
        $wpdb->candidates = [ 44 => [ 'id' => 44, 'keyword' => 'gamma cross pool phrase', 'status' => 'approved' ] ];
        $wpdb->batches    = [ 5 => $this->batch( 5, 'category', 701, 'Category Target' ), 6 => $this->batch( 6, 'model', 0, 'Model Pool' ) ];
        $wpdb->rows       = [
            120 => $this->row( 120, 5, 'gamma cross pool phrase', 44 ),
            121 => $this->row( 121, 6, 'gamma cross pool phrase', 44, 'approved' ),
        ];

        $this->scoped_reject( new KeywordPoolImportBatchRepository(), 120 );

        $this->assertSame( 'rejected', $wpdb->rows[120]['status'] );
        $this->assertSame( 'approved', $wpdb->rows[121]['status'], 'Model-pool row must be untouched.' );
        $this->assertSame( 'approved', $wpdb->candidates[44]['status'] );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    public function test_rejecting_model_row_does_not_affect_category_pool_row(): void {
        $wpdb = $this->wpdb();
        $wpdb->candidates = [ 45 => [ 'id' => 45, 'keyword' => 'delta cross pool phrase', 'status' => 'approved' ] ];
        $wpdb->batches    = [ 7 => $this->batch( 7, 'model', 0, 'Model Pool' ), 8 => $this->batch( 8, 'category', 702, 'Category Target' ) ];
        $wpdb->rows       = [
            130 => $this->row( 130, 7, 'delta cross pool phrase', 45 ),
            131 => $this->row( 131, 8, 'delta cross pool phrase', 45, 'approved' ),
        ];

        $this->scoped_reject( new KeywordPoolImportBatchRepository(), 130 );

        $this->assertSame( 'rejected', $wpdb->rows[130]['status'] );
        $this->assertSame( 'approved', $wpdb->rows[131]['status'], 'Category-pool row must be untouched.' );
        $this->assertSame( 'approved', $wpdb->candidates[45]['status'] );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    // ── 5. Duplicate sibling in the same batch ────────────────────────────

    public function test_rejecting_one_duplicate_row_leaves_sibling_untouched(): void {
        $wpdb = $this->wpdb();
        $wpdb->candidates = [ 46 => [ 'id' => 46, 'keyword' => 'epsilon duplicate phrase', 'status' => 'queued_for_review' ] ];
        $wpdb->batches    = [ 9 => $this->batch( 9, 'category', 703, 'Target A' ) ];
        $wpdb->rows       = [
            140 => $this->row( 140, 9, 'epsilon duplicate phrase', 46 ),
            141 => $this->row( 141, 9, 'epsilon duplicate phrase', 0 ),
        ];

        $this->scoped_reject( new KeywordPoolImportBatchRepository(), 140 );

        $this->assertSame( 'rejected', $wpdb->rows[140]['status'] );
        $this->assertSame( 'queued_for_review', $wpdb->rows[141]['status'], 'Duplicate sibling row must be untouched.' );
        $this->assertSame( 'queued_for_review', $wpdb->candidates[46]['status'] );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    // ── 6 & 7. Missing and stale candidate IDs ────────────────────────────

    public function test_missing_candidate_id_allows_row_only_rejection(): void {
        $wpdb = $this->wpdb();
        $wpdb->batches = [ 10 => $this->batch( 10, 'category', 704, 'Target A' ) ];
        $wpdb->rows    = [ 150 => $this->row( 150, 10, 'zeta unlinked phrase', 0 ) ];

        $this->assertTrue( $this->scoped_reject( new KeywordPoolImportBatchRepository(), 150 ) );
        $this->assertSame( 'rejected', $wpdb->rows[150]['status'] );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    public function test_stale_nonexistent_candidate_id_allows_row_only_rejection(): void {
        $wpdb = $this->wpdb();
        $wpdb->batches = [ 11 => $this->batch( 11, 'category', 705, 'Target A' ) ];
        $wpdb->rows    = [ 160 => $this->row( 160, 11, 'eta stale phrase', 999999 ) ];

        $this->assertTrue( $this->scoped_reject( new KeywordPoolImportBatchRepository(), 160 ) );
        $this->assertSame( 'rejected', $wpdb->rows[160]['status'] );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    // ── 8. Batch counts reflect the rejection ─────────────────────────────

    public function test_batch_counts_update_after_rejection(): void {
        $wpdb = $this->wpdb();
        $wpdb->batches = [ 12 => $this->batch( 12, 'category', 706, 'Target A' ) ];
        $wpdb->rows    = [
            170 => $this->row( 170, 12, 'theta count phrase one', 0 ),
            171 => $this->row( 171, 12, 'theta count phrase two', 0, 'approved' ),
        ];
        $repository = new KeywordPoolImportBatchRepository();

        $this->scoped_reject( $repository, 170 );
        $repository->recalculate_batch_counts( 12 );

        $this->assertSame( 1, (int) $wpdb->batches[12]['rejected'], 'Rejected count must reflect the row.' );
        $this->assertSame( 1, (int) $wpdb->batches[12]['approved'] );
        $batch_writes = array_filter( $wpdb->writes, fn ( $w ) => false !== stripos( $w['table'], 'import_batches' ) );
        $this->assertNotEmpty( $batch_writes, 'Batch counts write must occur.' );
        $this->assertCandidateTableUntouched( $wpdb );
    }

    // ── 9. Rejected filter and repository support ─────────────────────────

    public function test_rejected_status_filter_and_counts_are_supported(): void {
        $repository_source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php' );
        $this->assertStringContainsString( "'rejected' => __('Rejected', 'tmwseo')", self::$admin_source );
        $this->assertStringContainsString( "SUM(status = 'rejected') AS rejected", $repository_source );
    }

    // ── 10. Redirect/query state preservation ─────────────────────────────

    public function test_redirect_and_query_state_tokens_are_preserved(): void {
        foreach ( [
            "tmwseo_notice' => 'import_row_' . \$requested_action",
            'tmwseo_keyword_batch_page',
            "current_import_row_sort_from_array(\$_POST)",
            "current_pool_search_from_array(\$_POST)",
            "current_import_row_status_from_array(\$_POST)",
            'tmwseo_import_row_inspect',
        ] as $token ) {
            $this->assertStringContainsString( $token, self::$admin_source, 'Query-state token missing: ' . $token );
        }
    }

    // ── 11. Delete Batch path untouched by the reject branch ──────────────

    public function test_reject_branch_contains_no_delete_batch_calls(): void {
        $this->assertNotSame( '', self::$reject_branch, 'Scoped reject markers must exist in the handler.' );
        foreach ( [ 'delete_batch', 'delete(', 'DELETE FROM' ] as $token ) {
            $this->assertStringNotContainsString( $token, self::$reject_branch );
        }
    }

    // ── 12. Approval semantics unchanged ──────────────────────────────────

    public function test_approval_path_semantics_are_unchanged(): void {
        $this->assertStringContainsString( "if (\$candidate_id > 0 && \$repository->update_candidate_status(\$candidate_id, 'approved'))", self::$admin_source );
        $this->assertStringContainsString( 'approve_import_row_as_candidate_result($row, $batch)', self::$admin_source );
        $this->assertStringContainsString( "'result_reason' => 'manually_approved'", self::$admin_source );
    }

    // ── 13. Regression guard on the ACTUAL Reject path ────────────────────

    public function test_reject_path_never_mutates_candidate_status(): void {
        $this->assertNotSame( '', self::$reject_branch, 'Scoped reject markers must exist in the handler.' );
        foreach ( [
            'update_candidate_status',
            '->save(',
            "'ignored'",
            '"ignored"',
            'tmw_keyword_candidates',
            '$wpdb->update',
            '$wpdb->query',
        ] as $forbidden ) {
            $this->assertStringNotContainsString(
                $forbidden,
                self::$reject_branch,
                'Forbidden candidate mutation reference in the manual Reject path: ' . $forbidden
            );
        }
        // Exactly one Reject path exists: the handler must contain exactly one
        // begin marker, so no second unguarded reject branch can hide elsewhere.
        $this->assertSame( 1, substr_count( self::$admin_source, '[TMW-KW-SCOPED-REJECT] begin row-only rejection' ) );
        $this->assertSame( 1, substr_count( self::$admin_source, "update_candidate_status(\$candidate_id, 'approved')" ), 'update_candidate_status must remain only in the approve branch.' );
        $this->assertSame( 1, substr_count( self::$admin_source, 'update_candidate_status(' ), 'No other update_candidate_status call site may exist in the admin page.' );
    }

    // ── 14. No unrelated write surfaces in the reject branch ──────────────

    public function test_reject_branch_touches_no_unrelated_write_surfaces(): void {
        foreach ( [
            'rank_math', 'update_post_meta', 'add_post_meta', 'delete_post_meta',
            'update_option', 'add_option', 'delete_option', 'set_transient',
            'wp_insert_post', 'wp_update_post', 'wp_set_object_terms',
            'wp_publish_post', 'noindex', 'wp_cache_set', 'clean_post_cache',
        ] as $token ) {
            $this->assertStringNotContainsString( $token, self::$reject_branch, 'Unsafe write surface in reject branch: ' . $token );
        }
        // The branch performs exactly the intended row update and a log line.
        $this->assertStringContainsString( 'update_import_row', self::$reject_branch );
        $this->assertStringContainsString( "'result_reason' => 'manually_rejected_row_only'", self::$reject_branch );
        $this->assertStringContainsString( '[TMW-KW-SCOPED-REJECT] row=%d batch=%d prior_status=%s new_status=%s candidate_id=%d candidate_mutation=skipped', self::$reject_branch );
    }

    // ── 15. No category-specific hardcoding ───────────────────────────────

    public function test_no_category_specific_hardcoding(): void {
        $this->assertNotSame( '', self::$reject_branch );
        foreach ( [ 'Free Cam Chat', 'Live Cam Chat', 'live jasmin', 'livejasmin' ] as $forbidden ) {
            $this->assertFalse(
                stripos( self::$reject_branch, $forbidden ),
                'Hardcoded audit example found in reject branch: ' . $forbidden
            );
        }
        $this->assertStringContainsString( 'Rejecting an import row affects this review row only.', self::$admin_source );
    }

    // ── UI reason renders for both old and new rejected rows ──────────────

    public function test_existing_and_new_rejected_reasons_both_render(): void {
        // result_reason is rendered as plain text in the Result/Reason column,
        // so legacy 'manually_rejected' rows and new 'manually_rejected_row_only'
        // rows both display without special-casing. Assert nothing filters the
        // legacy reason out.
        $this->assertStringNotContainsString( "!== 'manually_rejected'", self::$admin_source );
        $this->assertStringNotContainsString( "unset", self::$reject_branch );
    }
}
