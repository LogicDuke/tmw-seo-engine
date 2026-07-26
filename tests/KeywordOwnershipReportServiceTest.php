<?php
/**
 * Tests for KeywordOwnershipReportService (PR-A, read-only diagnostics).
 *
 * Isolated unit tests: fixture rows are injected through the protected
 * fetcher seams via a Testable subclass; no live WordPress database is used.
 * The write-safety test additionally runs the REAL fetch paths against a
 * recording $wpdb stub and asserts zero mutating calls.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordOwnershipReportService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-ownership-report-service.php';

/**
 * Fixture-driven testable subclass: overrides every DB seam.
 */
final class KeywordOwnershipReportServiceTestable extends KeywordOwnershipReportService {

    public array $fixture_candidates       = [];
    public array $fixture_rows_by_cid      = [];
    public array $fixture_rows_by_keyword  = [];
    public array $fixture_batches          = [];
    public array $fixture_postmeta         = [];
    public array $fixture_usage            = [];
    public array $fixture_cannibal         = [];
    public array $fixture_pages            = [];
    public array $fixture_existing_tables  = [];

    protected function table_exists( string $table ): bool {
        return in_array( $table, $this->fixture_existing_tables, true );
    }

    protected function get_columns( string $table ): array {
        return [ 'id' => true, 'keyword' => true, 'canonical' => true, 'normalized_keyword' => true, 'keyword_text' => true ];
    }

    protected function fetch_candidates_chunk( int $after_id, int $limit ): array {
        $rows = [];
        foreach ( $this->fixture_candidates as $candidate ) {
            if ( (int) $candidate['id'] > $after_id ) { $rows[] = $candidate; }
            if ( count( $rows ) >= $limit ) { break; }
        }
        return $rows;
    }

    protected function build_cluster_map(): void {
        // Rebuild from fixtures using the same production key logic.
        $map = [];
        foreach ( $this->fixture_candidates as $candidate ) {
            $key = strtolower( (string) ( '' !== trim( (string) ( $candidate['canonical'] ?? '' ) ) ? $candidate['canonical'] : $candidate['keyword'] ) );
            $key = (string) preg_replace( '/[^a-z0-9]+/', '', $key );
            if ( '' === $key ) { continue; }
            if ( ! isset( $map[ $key ] ) ) {
                $map[ $key ] = [ 'count' => 0, 'hash' => substr( sha1( $key ), 0, 10 ) ];
            }
            $map[ $key ]['count']++;
        }
        $reflection = new \ReflectionProperty( KeywordOwnershipReportService::class, 'cluster_map' );
        $reflection->setValue( $this, $map );
    }

    protected function fetch_import_rows_by_candidate_ids( array $candidate_ids ): array {
        $rows = [];
        foreach ( $this->fixture_rows_by_cid as $row ) {
            if ( in_array( (int) ( $row['candidate_id'] ?? 0 ), $candidate_ids, true ) ) { $rows[] = $row; }
        }
        return $rows;
    }

    protected function fetch_import_rows_by_keywords( array $keywords ): array {
        $rows = [];
        foreach ( $this->fixture_rows_by_keyword as $row ) {
            if ( in_array( (string) ( $row['normalized_keyword'] ?? '' ), $keywords, true ) ) { $rows[] = $row; }
        }
        return $rows;
    }

    protected function fetch_batches( array $batch_ids ): array {
        $rows = [];
        foreach ( $this->fixture_batches as $batch ) {
            if ( in_array( (int) $batch['id'], $batch_ids, true ) ) { $rows[] = $batch; }
        }
        return $rows;
    }

    protected function fetch_postmeta_ownership( array $keywords ): array { return $this->fixture_postmeta; }
    protected function fetch_usage_rows( array $keywords ): array         { return $this->fixture_usage; }
    protected function fetch_cannibalization_rows( array $keywords ): array { return $this->fixture_cannibal; }
    protected function fetch_pages( array $post_ids ): array {
        $pages = [];
        foreach ( $post_ids as $pid ) {
            if ( isset( $this->fixture_pages[ $pid ] ) ) { $pages[ $pid ] = $this->fixture_pages[ $pid ]; }
        }
        return $pages;
    }
}

/**
 * Recording wpdb stub: read calls succeed with empty data; any mutating call
 * is recorded so the write-safety test can assert none happened.
 */
class KeywordOwnershipReportRecordingWpdb {
    public string $prefix   = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $posts    = 'wp_posts';
    public array  $mutations = [];
    public array  $read_sql  = [];

    public function prepare( string $sql, ...$args ): string {
        $i = 0;
        return (string) preg_replace_callback( '/%[sdf]/', function () use ( $args, &$i ) {
            $value = $args[ $i++ ] ?? '';
            return is_string( $value ) ? "'" . addslashes( $value ) . "'" : (string) $value;
        }, $sql );
    }
    public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
    public function get_var( string $sql ) {
        $this->read_sql[] = $sql;
        // Report only the required candidates table as existing.
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) && false !== stripos( $sql, 'tmw_keyword_candidates' ) ) {
            return 'wp_tmw_keyword_candidates';
        }
        return null;
    }
    public function get_row( string $sql, string $output = 'OBJECT' ) { $this->read_sql[] = $sql; return null; }
    public function get_results( string $sql, string $output = 'OBJECT' ): array { $this->read_sql[] = $sql; return []; }
    public function get_col( string $sql ): array { $this->read_sql[] = $sql; return []; }
    public function query( string $sql ) { $this->mutations[] = 'query:' . $sql; return 0; }
    public function insert( ...$args ) { $this->mutations[] = 'insert'; return false; }
    public function update( ...$args ) { $this->mutations[] = 'update'; return false; }
    public function delete( ...$args ) { $this->mutations[] = 'delete'; return false; }
    public function replace( ...$args ) { $this->mutations[] = 'replace'; return false; }
}

final class KeywordOwnershipReportServiceTest extends TestCase {

    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ── Fixture helpers (generic names only; no production category data) ──

    private function service(): KeywordOwnershipReportServiceTestable {
        $service = new KeywordOwnershipReportServiceTestable();
        $service->fixture_existing_tables = [
            'wp_tmw_keyword_candidates',
            'wp_tmw_keyword_import_batches',
            'wp_tmw_keyword_import_rows',
            'wp_tmw_cannibalization_flags',
            'wp_tmwseo_keyword_usage',
        ];
        return $service;
    }

    private function candidate( int $id, string $keyword, array $extra = [] ): array {
        return array_merge( [
            'id'          => $id,
            'keyword'     => $keyword,
            'canonical'   => $keyword,
            'status'      => 'queued_for_review',
            'intent_type' => 'category',
            'entity_type' => 'category',
            'entity_id'   => 0,
            'target_type' => '',
            'target_id'   => 0,
            'target_name' => '',
            'target_slug' => '',
        ], $extra );
    }

    private function importRow( int $id, int $batch_id, string $keyword, array $extra = [] ): array {
        return array_merge( [
            'id'                 => $id,
            'batch_id'           => $batch_id,
            'import_batch_id'    => 'batch-' . $batch_id,
            'keyword'            => $keyword,
            'normalized_keyword' => $keyword,
            'status'             => 'approved',
            'result_action'      => 'approved',
            'result_reason'      => 'manually_approved',
            'target_type'        => '',
            'target_id'          => 0,
            'target_name'        => '',
            'candidate_id'       => 0,
        ], $extra );
    }

    private function batch( int $id, string $pool, int $target_id, string $target_name ): array {
        return [
            'id'              => $id,
            'import_batch_id' => 'batch-' . $id,
            'pool'            => $pool,
            'target_type'     => 'tmw_category_page',
            'target_id'       => $target_id,
            'target_name'     => $target_name,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rows( KeywordOwnershipReportServiceTestable $service, array $filters = [] ): array {
        return iterator_to_array( $service->run( $filters ), false );
    }

    public function test_assignment_diagnostics_skip_orphan_join_when_candidates_table_missing(): void {
        $wpdb = new KeywordOwnershipReportRecordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $service = new KeywordOwnershipReportServiceTestable();
        $service->fixture_existing_tables = [ 'wp_tmw_keyword_assignments' ];

        $summary = $service->summary();

        $this->assertSame( 1, $summary['assignments_table_present'] );
        $this->assertSame( 0, $summary['orphan_assignments'] );
        foreach ( $wpdb->read_sql as $sql ) {
            $this->assertStringNotContainsString( 'LEFT JOIN wp_tmw_keyword_candidates', $sql );
        }
        $this->assertSame( [], $wpdb->mutations );
    }

    // ── 1. Shared candidate_id across two batches ─────────────────────────

    public function test_shared_candidate_id_across_two_batches_is_reported(): void {
        $service = $this->service();
        $service->fixture_candidates  = [ $this->candidate( 10, 'alpha phrase one', [ 'status' => 'approved' ] ) ];
        $service->fixture_batches     = [ $this->batch( 1, 'category', 501, 'Target A' ), $this->batch( 2, 'category', 501, 'Target A' ) ];
        $service->fixture_rows_by_cid = [
            $this->importRow( 100, 1, 'alpha phrase one', [ 'candidate_id' => 10 ] ),
            $this->importRow( 101, 2, 'alpha phrase one', [ 'candidate_id' => 10 ] ),
        ];

        $rows = $this->rows( $service );

        $this->assertCount( 1, $rows );
        $this->assertTrue( $rows[0]['candidate_id_shared_across_batches'] );
        $this->assertSame( 1, $service->summary()['shared_candidate_ids_across_batches'] );
    }

    // ── 2. Same keyword across two targets ────────────────────────────────

    public function test_same_keyword_across_two_targets_is_reported(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 11, 'beta phrase two', [ 'target_type' => 'tmw_category_page', 'target_id' => 601, 'target_name' => 'Target A' ] ) ];
        $service->fixture_batches    = [ $this->batch( 3, 'category', 602, 'Target B' ) ];
        $service->fixture_rows_by_keyword = [
            $this->importRow( 110, 3, 'beta phrase two', [ 'result_action' => 'manual_approval_failed', 'result_reason' => 'existing_keyword_has_different_target' ] ),
        ];

        $rows = $this->rows( $service );

        $this->assertCount( 2, $rows[0]['distinct_targets'] );
        $this->assertTrue( $rows[0]['blocked_different_target_history'] );
        $summary = $service->summary();
        $this->assertSame( 1, $summary['candidates_referenced_by_multiple_targets'] );
        $this->assertSame( 1, $summary['blocked_due_to_different_target'] );
    }

    // ── 3. Cross-pool collision ───────────────────────────────────────────

    public function test_cross_pool_collision_is_reported(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 12, 'gamma phrase three', [ 'intent_type' => 'category' ] ) ];
        $service->fixture_batches    = [ $this->batch( 4, 'model', 701, 'Model Batch Target' ) ];
        $service->fixture_rows_by_keyword = [ $this->importRow( 120, 4, 'gamma phrase three' ) ];

        $rows = $this->rows( $service );

        $this->assertTrue( $rows[0]['cross_pool_collision'] );
        $this->assertSame( 'cross_pool_conflict', $rows[0]['resolution_state'] );
        $this->assertSame( 1, $service->summary()['cross_pool_conflicts'] );
    }

    // ── 4. Duplicate rows in one batch ────────────────────────────────────

    public function test_duplicate_rows_in_one_batch_are_reported(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 13, 'delta phrase four' ) ];
        $service->fixture_batches    = [ $this->batch( 5, 'category', 801, 'Target A' ) ];
        $service->fixture_rows_by_keyword = [
            $this->importRow( 130, 5, 'delta phrase four' ),
            $this->importRow( 131, 5, 'delta phrase four', [ 'result_reason' => 'duplicate_in_upload_skipped' ] ),
        ];

        $rows = $this->rows( $service );

        $this->assertTrue( $rows[0]['duplicate_rows_same_batch'] );
        $this->assertFalse( $rows[0]['duplicate_rows_cross_batch'] );
        $this->assertSame( 1, $service->summary()['duplicate_import_rows_same_batch'] );
    }

    // ── 5. Approved but unused ────────────────────────────────────────────

    public function test_approved_but_unused_is_reported(): void {
        $service = $this->service();
        $service->fixture_candidates = [
            $this->candidate( 14, 'epsilon phrase five', [ 'status' => 'approved', 'target_type' => 'tmw_category_page', 'target_id' => 901 ] ),
            $this->candidate( 28, 'omicron phrase sixteen', [ 'status' => 'approved' ] ), // approved, ownerless
        ];
        $service->fixture_pages = [
            901 => [ 'post_type' => 'tmw_category_page', 'rankmath_csv' => 'other primary phrase', 'content_normalized' => 'body text without the phrase' ],
        ];

        $rows = $this->rows( $service );

        $this->assertTrue( $rows[0]['approved_but_unused'] );
        $this->assertTrue( $rows[0]['stale_owner'] );
        // Owned + unused escalates to the more severe stale_owner verdict.
        $this->assertSame( 'stale_owner', $rows[0]['resolution_state'] );
        // Ownerless approved + unused keeps the plain approved_unused verdict.
        $this->assertTrue( $rows[1]['approved_but_unused'] );
        $this->assertFalse( $rows[1]['stale_owner'] );
        $this->assertSame( 'approved_unused', $rows[1]['resolution_state'] );
        $summary = $service->summary();
        $this->assertSame( 2, $summary['approved_but_unused'] );
        $this->assertSame( 1, $summary['stale_owners'] );
    }

    // ── 6. Rank Math active, content missing ──────────────────────────────

    public function test_rankmath_active_content_missing_is_reported(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 15, 'zeta phrase six', [ 'status' => 'approved', 'target_type' => 'tmw_category_page', 'target_id' => 902 ] ) ];
        $service->fixture_pages = [
            902 => [ 'post_type' => 'tmw_category_page', 'rankmath_csv' => 'main phrase, zeta phrase six', 'content_normalized' => 'body text that never mentions it' ],
        ];

        $rows = $this->rows( $service );

        $this->assertTrue( $rows[0]['active_but_unsupported'] );
        $this->assertSame( 'extra', $rows[0]['rankmath_presence'][0]['rankmath_role'] );
        $this->assertFalse( $rows[0]['content_presence'][0]['present'] );
        $this->assertSame( 1, $service->summary()['rankmath_active_content_missing'] );
    }

    // ── 7. Filters ────────────────────────────────────────────────────────

    public function test_conflicts_only_filter_skips_clean_rows(): void {
        $service = $this->service();
        $service->fixture_candidates = [
            $this->candidate( 16, 'clean phrase seven', [ 'target_type' => 'tmw_category_page', 'target_id' => 903 ] ),
            $this->candidate( 17, 'conflicted phrase eight' ),
        ];
        $service->fixture_batches = [ $this->batch( 6, 'model', 904, 'Other Pool Target' ) ];
        $service->fixture_rows_by_keyword = [ $this->importRow( 160, 6, 'conflicted phrase eight' ) ];

        $rows = $this->rows( $service, [ 'conflicts_only' => true ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( 17, $rows[0]['candidate_id'] );
        // Summary still counts the full dataset.
        $this->assertSame( 2, $service->summary()['total_candidate_identities'] );
    }

    public function test_keyword_filter_normalizes_its_input(): void {
        $service = $this->service();
        $service->fixture_candidates = [
            $this->candidate( 18, 'eta phrase nine' ),
            $this->candidate( 19, 'unrelated phrase ten' ),
        ];

        // Case, surplus whitespace, and stripped punctuation all normalize away.
        $rows = $this->rows( $service, [ 'keyword' => '  ETA   Phrase!! nine ' ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( 18, $rows[0]['candidate_id'] );
    }

    public function test_pool_and_target_filters(): void {
        $service = $this->service();
        $service->fixture_candidates = [
            $this->candidate( 20, 'theta phrase eleven', [ 'intent_type' => 'model' ] ),
            $this->candidate( 21, 'iota phrase twelve', [ 'intent_type' => 'category', 'target_type' => 'tmw_category_page', 'target_id' => 905 ] ),
        ];

        $model_rows  = $this->rows( $service, [ 'pool' => 'model' ] );
        $target_rows = $this->rows( $service, [ 'target_id' => 905 ] );

        $this->assertCount( 1, $model_rows );
        $this->assertSame( 20, $model_rows[0]['candidate_id'] );
        $this->assertCount( 1, $target_rows );
        $this->assertSame( 21, $target_rows[0]['candidate_id'] );
    }

    // ── 8. Write-safety (real fetch paths, recording wpdb) ────────────────

    public function test_service_performs_no_writes_on_real_paths(): void {
        $wpdb = new KeywordOwnershipReportRecordingWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $service = new KeywordOwnershipReportService();
        $rows    = iterator_to_array( $service->run(), false );

        $this->assertSame( [], $rows, 'Empty candidates table yields no rows.' );
        $this->assertSame( [], $wpdb->mutations, 'No mutating wpdb call may occur.' );
        $this->assertNotEmpty( $wpdb->read_sql, 'Read queries were issued.' );
        $summary = $service->summary();
        $this->assertSame( 0, $summary['total_candidate_identities'] );
    }

    public function test_service_source_contains_no_write_method_names(): void {
        $source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-ownership-report-service.php' );
        foreach ( [
            '$wpdb->insert', '$wpdb->update', '$wpdb->delete', '$wpdb->replace',
            'update_post_meta', 'add_post_meta', 'delete_post_meta',
            'update_option', 'add_option', 'delete_option',
            'set_transient', 'delete_transient', 'wp_cache_set',
            'wp_insert_post', 'wp_update_post', 'dbDelta',
        ] as $forbidden ) {
            $this->assertFalse(
                strpos( $source, $forbidden ),
                'Forbidden write reference found in service source: ' . $forbidden
            );
        }
    }

    // ── 9. Missing optional tables fail safe ──────────────────────────────

    public function test_missing_optional_tables_fail_safe(): void {
        $service = $this->service();
        $service->fixture_existing_tables = [ 'wp_tmw_keyword_candidates', 'wp_tmw_keyword_import_batches', 'wp_tmw_keyword_import_rows' ];
        $service->fixture_candidates = [ $this->candidate( 22, 'kappa phrase thirteen' ) ];

        $rows    = $this->rows( $service );
        $summary = $service->summary();

        $this->assertCount( 1, $rows );
        $this->assertSame( 'table_missing', $rows[0]['cannibalization_flags'] );
        $this->assertSame( 'table_missing', $rows[0]['usage_registry'] );
        $this->assertStringContainsString( 'wp_tmw_cannibalization_flags', (string) $summary['optional_tables_missing'] );
        $this->assertStringContainsString( 'wp_tmwseo_keyword_usage', (string) $summary['optional_tables_missing'] );
    }

    public function test_missing_candidates_table_aborts_cleanly(): void {
        $service = $this->service();
        $service->fixture_existing_tables = [];

        $rows = $this->rows( $service );

        $this->assertSame( [], $rows );
        $this->assertSame( 1, $service->summary()['candidates_table_missing'] ?? 0 );
    }

    // ── 10. No category-specific hardcoding ───────────────────────────────

    public function test_no_category_specific_hardcoding_in_service_or_cli(): void {
        $sources = [
            (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-ownership-report-service.php' ),
        ];
        // Only scan the newly added CLI subcommand block, not unrelated legacy subcommands.
        $cli = (string) file_get_contents( __DIR__ . '/../includes/cli/class-cli.php' );
        $start = strpos( $cli, 'keyword_ownership_report' );
        $sources[] = false !== $start ? substr( $cli, $start ) : '';

        foreach ( $sources as $source ) {
            foreach ( [ 'Free Cam Chat', 'Live Cam Chat', 'live jasmin', 'livejasmin' ] as $forbidden ) {
                $this->assertFalse(
                    stripos( $source, $forbidden ),
                    'Audit example data must not be hardcoded: ' . $forbidden
                );
            }
        }
    }

    // ── 11. Near-duplicate clustering ─────────────────────────────────────

    public function test_near_duplicate_clusters_share_an_id(): void {
        $service = $this->service();
        $service->fixture_candidates = [
            $this->candidate( 23, 'lambda mu phrase' ),
            $this->candidate( 24, 'lambdamu phrase' ),
            $this->candidate( 25, 'totally different phrase' ),
        ];

        $rows = $this->rows( $service );

        $this->assertNotSame( '', $rows[0]['near_duplicate_cluster_id'] );
        $this->assertSame( $rows[0]['near_duplicate_cluster_id'], $rows[1]['near_duplicate_cluster_id'] );
        $this->assertSame( '', $rows[2]['near_duplicate_cluster_id'] );
        $this->assertSame( 1, $service->summary()['near_duplicate_clusters'] );

        $dup_only = $this->rows( $service, [ 'duplicates_only' => true ] );
        $this->assertCount( 2, $dup_only );
    }

    // ── 12. Stale owner via active-but-unsupported ────────────────────────

    public function test_stale_owner_when_active_chip_lacks_content(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 26, 'nu phrase fourteen', [ 'status' => 'approved', 'target_type' => 'tmw_category_page', 'target_id' => 906 ] ) ];
        $service->fixture_pages = [
            906 => [ 'post_type' => 'tmw_category_page', 'rankmath_csv' => 'nu phrase fourteen', 'content_normalized' => 'unrelated body' ],
        ];

        $rows = $this->rows( $service );

        $this->assertTrue( $rows[0]['stale_owner'] );
        $this->assertSame( 'primary', $rows[0]['rankmath_presence'][0]['rankmath_role'] );
        $this->assertSame( 'rankmath_active_content_missing', $rows[0]['resolution_state'] );
    }

    // ── Registry pass-through and unresolvable targets ────────────────────

    public function test_registries_and_unresolvable_targets_surface(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 27, 'xi phrase fifteen', [ 'target_type' => 'tmw_category_page', 'target_id' => 907 ] ) ];
        $service->fixture_postmeta = [ 'xi phrase fifteen' => [ [ 'post_id' => 42, 'role' => 'primary' ] ] ];
        $service->fixture_usage    = [ 'xi phrase fifteen' => [ [ 'keyword_text' => 'xi phrase fifteen', 'use_count' => 3 ] ] ];
        $service->fixture_cannibal = [ 'xi phrase fifteen' => [ [ 'keyword_text' => 'xi phrase fifteen', 'severity' => 'warning' ] ] ];
        $service->fixture_pages[42] = [ 'post_type' => 'post', 'rankmath_csv' => '', 'content_normalized' => '' ];
        // No page fixture for 907 → unresolvable.

        $rows = $this->rows( $service );

        $this->assertSame( [ 907 ], $rows[0]['target_unresolvable'] );
        $this->assertSame( 'primary', $rows[0]['postmeta_ownership'][0]['role'] );
        $this->assertSame( 3, $rows[0]['usage_registry'][0]['use_count'] );
        $this->assertSame( 'warning', $rows[0]['cannibalization_flags'][0]['severity'] );
    }

    public function test_category_entity_id_is_not_guessed_to_be_a_post_id(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 29, 'registry phrase', [ 'entity_id' => 990 ] ) ];
        $service->fixture_pages[990] = [ 'post_type' => 'post', 'rankmath_csv' => 'registry phrase', 'content_normalized' => 'registry phrase' ];

        $row = $this->rows( $service )[0];

        $this->assertSame( [], $row['rankmath_presence'] );
        $this->assertSame( [ 990 ], $row['target_unresolvable'] );
    }

    public function test_post_backed_entity_id_resolves_directly(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 30, 'model registry phrase', [ 'entity_type' => 'model', 'entity_id' => 991 ] ) ];
        $service->fixture_pages[991] = [ 'post_type' => 'model', 'rankmath_csv' => 'model registry phrase', 'content_normalized' => 'model registry phrase' ];

        $row = $this->rows( $service )[0];

        $this->assertSame( 'primary', $row['rankmath_presence'][0]['rankmath_role'] );
        $this->assertSame( [], $row['target_unresolvable'] );
    }

    public function test_candidate_owner_and_linked_target_are_shared_across_targets(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 31, 'shared owner phrase', [ 'target_type' => 'tmw_category_page', 'target_id' => 1001 ] ) ];
        $service->fixture_batches = [ $this->batch( 31, 'category', 1002, 'Target B' ) ];
        $service->fixture_rows_by_cid = [ $this->importRow( 310, 31, 'shared owner phrase', [ 'candidate_id' => 31 ] ) ];

        $this->assertCount( 1, $this->rows( $service, [ 'shared_candidate_ids_only' => true ] ) );
        $this->assertCount( 1, $this->rows( $service, [ 'conflicts_only' => true ] ) );
    }

    public function test_repeated_candidate_links_to_owner_target_are_not_cross_target(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 32, 'same owner phrase', [ 'target_type' => 'tmw_category_page', 'target_id' => 1003 ] ) ];
        $service->fixture_batches = [ $this->batch( 32, 'category', 1003, 'Target A' ), $this->batch( 33, 'category', 1003, 'Target A' ) ];
        $service->fixture_rows_by_cid = [
            $this->importRow( 320, 32, 'same owner phrase', [ 'candidate_id' => 32 ] ),
            $this->importRow( 321, 33, 'same owner phrase', [ 'candidate_id' => 32 ] ),
        ];

        $this->assertFalse( $this->rows( $service )[0]['candidate_id_shared_across_targets'] );
    }

    public function test_primary_and_secondary_postmeta_pages_are_scanned_for_presence(): void {
        $service = $this->service();
        $service->fixture_candidates = [ $this->candidate( 33, 'owned registry phrase', [ 'status' => 'approved' ] ) ];
        $service->fixture_postmeta = [ 'owned registry phrase' => [
            [ 'post_id' => 1004, 'role' => 'primary' ],
            [ 'post_id' => 1005, 'role' => 'secondary' ],
        ] ];
        $service->fixture_pages = [
            1004 => [ 'post_type' => 'post', 'rankmath_csv' => '', 'content_normalized' => 'owned registry phrase' ],
            1005 => [ 'post_type' => 'post', 'rankmath_csv' => 'owned registry phrase', 'content_normalized' => 'unrelated body' ],
        ];

        $row = $this->rows( $service )[0];

        $this->assertFalse( $row['approved_but_unused'] );
        $this->assertTrue( $row['active_but_unsupported'] );
        $this->assertCount( 2, $row['rankmath_presence'] );
        $this->assertSame( 'secondary', $row['postmeta_ownership'][1]['role'] );
    }

    public function test_secondary_registry_source_is_loaded_once_when_reused_across_chunks(): void {
        $wpdb = new class extends KeywordOwnershipReportRecordingWpdb {
            public function get_results( string $sql, string $output = 'OBJECT' ): array {
                $this->read_sql[] = $sql;
                if ( false !== strpos( $sql, "meta_key = '_tmwseo_secondary_keywords'" ) ) {
                    return [ [ 'post_id' => 1006, 'meta_value' => '["boundary phrase"]' ] ];
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
        $service = new KeywordOwnershipReportService();
        $method = new \ReflectionMethod( KeywordOwnershipReportService::class, 'secondary_ownership_index' );

        $first_chunk  = $method->invoke( $service );
        $second_chunk = $method->invoke( $service );
        $secondary_queries = array_filter( $wpdb->read_sql, static function ( string $sql ): bool {
            return false !== strpos( $sql, "meta_key = '_tmwseo_secondary_keywords'" );
        } );

        $this->assertSame( $first_chunk, $second_chunk );
        $this->assertArrayHasKey( 'boundary phrase', $second_chunk );
        $this->assertCount( 1, $secondary_queries );
    }
}
