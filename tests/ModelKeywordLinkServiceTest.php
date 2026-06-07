<?php
/**
 * Tests for ModelKeywordLinkService — links approved personal model keyword
 * rows from entity_id=0 to the correct WordPress model post ID.
 *
 * Covers all required scenarios:
 *   1. Anisyia personal row is linked in real mode.
 *   2. Dry-run does not write.
 *   3. Global pool row (model_keyword_usage_scope=global_model_pool) is skipped.
 *   4. Row without personal_model_keyword_csv is skipped.
 *   5. Ambiguous owner is skipped; not-found owner is skipped.
 *   6. Status is not changed.
 *   7. Only entity_id (+ updated_at) is updated.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\ModelKeywordLinkService;
use TMWSEO\Engine\Models\ModelEntityResolver;

require_once __DIR__ . '/../includes/models/class-model-entity-resolver.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-link-service.php';

final class ModelKeywordLinkServiceTest extends TestCase {

    /** @var mixed Original global $wpdb to restore after each test. */
    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ── Scenario 1: Anisyia personal row is linked in real mode ──────────

    public function test_anisyia_personal_row_is_linked_to_correct_post_id(): void {
        $row  = $this->personal_row( 1, 'anisyia livejasmin', 'anisyia' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['scanned'],  'scanned should be 1' );
        $this->assertSame( 1, $stats['linked'],   'linked should be 1' );
        $this->assertSame( 0, $stats['skipped'],  'skipped should be 0' );
        $this->assertSame( 0, $stats['errors'],   'errors should be 0' );
        $this->assertFalse( $stats['dry_run'],    'dry_run flag should be false' );

        $this->assertCount( 1, $wpdb->updates, 'exactly one DB update should be written' );
        $this->assertSame( 4457, $wpdb->updates[0]['data']['entity_id'], 'entity_id should be 4457' );
        $this->assertSame(
            [ 'id' => 1, 'entity_id' => 0 ],
            $wpdb->updates[0]['where'],
            'WHERE clause must target id=1 AND entity_id=0 to guard against concurrent updates'
        );
    }

    // ── Scenario 2: Dry-run does not write ───────────────────────────────

    public function test_dry_run_does_not_write_to_database(): void {
        $row  = $this->personal_row( 2, 'anisyia cam', 'anisyia' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( true, 500 );

        $this->assertSame( 1, $stats['scanned'], 'scanned should be 1' );
        $this->assertSame( 1, $stats['linked'],  'linked counter should be 1 (dry-run would link)' );
        $this->assertSame( 0, $stats['skipped'], 'skipped should be 0' );
        $this->assertTrue( $stats['dry_run'],    'dry_run flag should be true' );

        $this->assertCount( 0, $wpdb->updates,   'NO DB updates should be written in dry-run' );

        $this->assertSame( 'dry_run_would_link', $stats['rows'][0]['action'] );
        $this->assertSame( 4457, $stats['rows'][0]['resolved_post_id'] );
    }

    // ── Scenario 3: Global pool row is skipped ───────────────────────────

    public function test_global_model_pool_row_is_skipped_regardless_of_owner(): void {
        $row = $this->personal_row( 3, 'livejasmin model', 'livejasmin' );
        // Mark as global pool in sources JSON.
        $row['sources'] = json_encode( [
            'personal_model_keyword_csv'   => true,
            'model_keyword_owner'          => 'livejasmin',
            'model_keyword_usage_scope'    => 'global_model_pool',
            'global_model_pool'            => true,
        ] );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [] )->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['scanned'], 'scanned should be 1' );
        $this->assertSame( 0, $stats['linked'],  'linked should be 0' );
        $this->assertSame( 1, $stats['skipped'], 'skipped should be 1' );

        $this->assertCount( 0, $wpdb->updates, 'NO DB updates for global pool row' );
        $this->assertSame( 'global_model_pool_row', $stats['rows'][0]['reason'] );
    }

    // ── Scenario 3b: DB target_type=global row is skipped ────────────────

    public function test_row_with_db_target_type_global_is_skipped(): void {
        $row                = $this->personal_row( 4, 'livejasmin models', 'livejasmin' );
        $row['target_type'] = 'global';
        $wpdb               = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb']    = $wpdb;

        $stats = $this->service_with_posts( [] )->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['skipped'] );
        $this->assertSame( 'global_target_type', $stats['rows'][0]['reason'] );
        $this->assertCount( 0, $wpdb->updates );
    }

    // ── Scenario 4: Row without personal_model_keyword_csv is skipped ────

    public function test_row_without_personal_model_keyword_csv_is_skipped(): void {
        $row = $this->personal_row( 5, 'anisyia live', 'anisyia' );
        // Override sources — personal_model_keyword_csv is absent.
        $row['sources'] = json_encode( [
            'model_keyword_owner'       => 'anisyia',
            'model_keyword_usage_scope' => 'model_generate',
        ] );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['skipped'] );
        $this->assertSame( 'missing_personal_model_keyword_csv', $stats['rows'][0]['reason'] );
        $this->assertCount( 0, $wpdb->updates );
    }

    // ── Scenario 5a: Ambiguous owner is skipped ──────────────────────────

    public function test_ambiguous_owner_match_is_skipped(): void {
        $row  = $this->personal_row( 6, 'anisyia', 'anisyia' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        // Two posts with the same slug → resolver returns match_type=ambiguous.
        $stats = $this->service_with_posts( [
            $this->model_post( 4457, 'Anisyia', 'anisyia' ),
            $this->model_post( 4458, 'Anisyia Duplicate', 'anisyia' ),
        ] )->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['skipped'] );
        $this->assertSame( 'owner_ambiguous', $stats['rows'][0]['reason'] );
        $this->assertCount( 0, $wpdb->updates );
    }

    // ── Scenario 5b: Not-found owner is skipped ──────────────────────────

    public function test_not_found_owner_is_skipped(): void {
        $row  = $this->personal_row( 7, 'unknownmodel livejasmin', 'unknownmodel' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [] )->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['skipped'] );
        $this->assertSame( 'owner_not_found', $stats['rows'][0]['reason'] );
        $this->assertCount( 0, $wpdb->updates );
    }

    // ── Scenario 6: Status is never changed ──────────────────────────────

    public function test_status_is_not_changed_during_link(): void {
        $row  = $this->personal_row( 8, 'anisyia cam', 'anisyia' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertCount( 1, $wpdb->updates );
        $this->assertArrayNotHasKey(
            'status',
            $wpdb->updates[0]['data'],
            'status must NOT be written by the link service'
        );
    }

    // ── Scenario 7: Only entity_id (+ updated_at) is written ─────────────

    public function test_only_entity_id_and_updated_at_are_written(): void {
        $row  = $this->personal_row( 9, 'anisyia live', 'anisyia' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertCount( 1, $wpdb->updates );
        $written_keys = array_keys( $wpdb->updates[0]['data'] );
        sort( $written_keys );

        // Only entity_id and updated_at are allowed.
        foreach ( $written_keys as $key ) {
            $this->assertContains(
                $key,
                [ 'entity_id', 'updated_at' ],
                "Unexpected key \"{$key}\" written by link service"
            );
        }
        $this->assertContains( 'entity_id', $written_keys );

        // WHERE must include entity_id=0 (concurrent-update guard).
        $this->assertSame(
            [ 'id' => 9, 'entity_id' => 0 ],
            $wpdb->updates[0]['where'],
            'WHERE clause must include entity_id=0'
        );
    }

    // ── Scenario: model_name filter restricts to matching owner ──────────

    public function test_model_name_filter_restricts_to_matching_owner(): void {
        $row_a = $this->personal_row( 10, 'anisyia livejasmin', 'anisyia' );
        $row_b = $this->personal_row( 11, 'bella livejasmin',   'bella' );
        $wpdb  = new ModelKeywordLinkFakeWpdb( [ $row_a, $row_b ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [
            $this->model_post( 4457, 'Anisyia', 'anisyia' ),
            $this->model_post( 4458, 'Bella',   'bella'   ),
        ] )->scan_and_link( false, 500, 'Anisyia' );

        $this->assertSame( 2, $stats['scanned'] );
        $this->assertSame( 1, $stats['linked'],  'only Anisyia row should be linked' );
        $this->assertSame( 1, $stats['skipped'], 'Bella row should be skipped by name filter' );

        $this->assertCount( 1, $wpdb->updates );
        $this->assertSame( 4457, $wpdb->updates[0]['data']['entity_id'] );
    }

    // ── Scenario: multiple personal rows for one model, all linked ────────

    public function test_multiple_personal_rows_for_one_model_are_all_linked(): void {
        $rows = [
            $this->personal_row( 12, 'anisyia',            'anisyia' ),
            $this->personal_row( 13, 'anisyia livejasmin', 'anisyia' ),
            $this->personal_row( 14, 'anisyia cam',        'anisyia' ),
            $this->personal_row( 15, 'anisyia live',       'anisyia' ),
        ];
        $wpdb = new ModelKeywordLinkFakeWpdb( $rows );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertSame( 4, $stats['scanned'] );
        $this->assertSame( 4, $stats['linked'] );
        $this->assertSame( 0, $stats['skipped'] );
        $this->assertCount( 4, $wpdb->updates );

        foreach ( $wpdb->updates as $update ) {
            $this->assertSame( 4457, $update['data']['entity_id'] );
        }
    }

    // ── Scenario: concurrent update is skipped, not linked or errored ────

    /**
     * When $wpdb->update() returns 0 (row no longer has entity_id=0 because
     * another process already linked it), the row must be counted as skipped
     * with reason 'concurrent_update', not as linked or errored.
     */
    public function test_concurrent_update_is_counted_as_skipped_not_linked(): void {
        $row  = $this->personal_row( 20, 'anisyia cam', 'anisyia' );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $wpdb->simulate_concurrent_ids[] = 20; // update() will return 0 for this id
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['scanned'] );
        $this->assertSame( 0, $stats['linked'],  'concurrent update must NOT be counted as linked' );
        $this->assertSame( 1, $stats['skipped'], 'concurrent update must be counted as skipped' );
        $this->assertSame( 0, $stats['errors'],  'concurrent update is not an error' );
        $this->assertSame( 'concurrent_update', $stats['rows'][0]['reason'] );

        // The UPDATE was attempted with the correct WHERE clause.
        $this->assertCount( 1, $wpdb->updates, 'update must be attempted even when it returns 0' );
        $this->assertSame(
            [ 'id' => 20, 'entity_id' => 0 ],
            $wpdb->updates[0]['where'],
            'WHERE must include entity_id=0'
        );
    }

    // ── Scenarios: global_model_pool flag with various value types ────────

    /**
     * sources.global_model_pool = "false" (string) must NOT trigger the global
     * pool guard — the row should be treated as a linkable personal keyword.
     */
    public function test_global_model_pool_string_false_is_not_treated_as_global(): void {
        $row             = $this->personal_row( 21, 'anisyia livejasmin', 'anisyia' );
        $row['sources']  = json_encode( [
            'personal_model_keyword_csv'   => true,
            'model_keyword_owner'          => 'anisyia',
            'model_keyword_usage_scope'    => 'model_generate',
            'global_model_pool'            => 'false',   // string "false" — must NOT be treated as global
        ] );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [ $this->model_post( 4457, 'Anisyia', 'anisyia' ) ] )
            ->scan_and_link( false, 500 );

        $this->assertSame( 1, $stats['linked'],  'string "false" must not trigger global pool guard' );
        $this->assertSame( 0, $stats['skipped'], 'row should not be skipped' );
        $this->assertCount( 1, $wpdb->updates );
    }

    /**
     * sources.global_model_pool = "true" (string) MUST trigger the global pool
     * guard — the row must be skipped as a Global Model Pool row.
     */
    public function test_global_model_pool_string_true_is_treated_as_global(): void {
        $row             = $this->personal_row( 22, 'livejasmin model', 'livejasmin' );
        $row['sources']  = json_encode( [
            'personal_model_keyword_csv'   => true,
            'model_keyword_owner'          => 'livejasmin',
            'model_keyword_usage_scope'    => 'model_generate',
            'global_model_pool'            => 'true',    // string "true" — MUST be treated as global
        ] );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [] )->scan_and_link( false, 500 );

        $this->assertSame( 0, $stats['linked'] );
        $this->assertSame( 1, $stats['skipped'] );
        $this->assertSame( 'global_model_pool_row', $stats['rows'][0]['reason'] );
        $this->assertCount( 0, $wpdb->updates, 'NO DB updates for global pool row' );
    }

    /**
     * sources.global_model_pool = true (PHP/JSON bool) MUST trigger the global
     * pool guard — the row must be skipped as a Global Model Pool row.
     */
    public function test_global_model_pool_boolean_true_is_treated_as_global(): void {
        $row             = $this->personal_row( 23, 'livejasmin model', 'livejasmin' );
        $row['sources']  = json_encode( [
            'personal_model_keyword_csv'   => true,
            'model_keyword_owner'          => 'livejasmin',
            'model_keyword_usage_scope'    => 'model_generate',
            'global_model_pool'            => true,      // PHP bool true → JSON true — MUST be treated as global
        ] );
        $wpdb = new ModelKeywordLinkFakeWpdb( [ $row ] );
        $GLOBALS['wpdb'] = $wpdb;

        $stats = $this->service_with_posts( [] )->scan_and_link( false, 500 );

        $this->assertSame( 0, $stats['linked'] );
        $this->assertSame( 1, $stats['skipped'] );
        $this->assertSame( 'global_model_pool_row', $stats['rows'][0]['reason'] );
        $this->assertCount( 0, $wpdb->updates, 'NO DB updates for global pool row' );
    }

    // ── Factory helpers ───────────────────────────────────────────────────

    /**
     * Build a service instance backed by a ModelEntityResolver that returns
     * the provided WP_Post stubs.
     */
    private function service_with_posts( array $posts ): ModelKeywordLinkService {
        return new ModelKeywordLinkService(
            new ModelEntityResolver( static fn() => $posts )
        );
    }

    /** Build a minimal WP_Post stub. */
    private function model_post( int $id, string $title, string $slug ): object {
        return (object) [
            'ID'         => $id,
            'post_title' => $title,
            'post_name'  => $slug,
            'post_type'  => 'model',
        ];
    }

    /**
     * Build a minimal approved personal model keyword row with proper sources JSON.
     *
     * The sources JSON mirrors what KeywordPoolSelectedImportService::provenance_for_row()
     * writes at import time when personal_model_keyword_csv=true.
     *
     * @param int    $id      Candidate row ID.
     * @param string $keyword Keyword text.
     * @param string $owner   Value of model_keyword_owner in sources JSON.
     * @param string $scope   model_keyword_usage_scope (default: model_generate —
     *                        the scope that bypasses entity resolution at import).
     */
    private function personal_row(
        int    $id,
        string $keyword,
        string $owner,
        string $scope = 'model_generate'
    ): array {
        return [
            'id'          => $id,
            'keyword'     => $keyword,
            'intent_type' => 'model',
            'entity_type' => 'model',
            'entity_id'   => 0,
            'status'      => 'approved',
            'target_type' => '',
            'sources'     => json_encode( [
                'personal_model_keyword_csv'   => true,
                'model_keyword_owner'          => $owner,
                'model_keyword_usage_scope'    => $scope,
                'global_model_pool'            => false,
            ] ),
        ];
    }
}

// ── Fake $wpdb ────────────────────────────────────────────────────────────────

/**
 * Minimal $wpdb stub for ModelKeywordLinkService tests.
 *
 * Handles:
 *   get_var()     — SHOW TABLES LIKE → table name (confirms table exists)
 *   get_results() — SHOW COLUMNS FROM → column list
 *                 — SELECT ... WHERE entity_id=0 → row list
 *   update()      — records writes without touching a real DB
 *
 * simulate_concurrent_ids: IDs listed here cause update() to return 0,
 * simulating a concurrent process that already changed entity_id before
 * this service's WHERE entity_id=0 clause could match.
 */
final class ModelKeywordLinkFakeWpdb {
    public string $prefix = 'wp_';

    /** @var array<int,array<string,mixed>> Rows keyed by id. */
    public array $rows = [];

    /** @var array<int,array<string,mixed>> All calls to update(). */
    public array $updates = [];

    /** @var array<int,string> All SQL strings passed to get_results() or prepare(). */
    public array $queries = [];

    /**
     * Row IDs for which update() returns 0 (simulates a concurrent link —
     * the WHERE entity_id=0 clause finds no matching row).
     *
     * @var array<int,int>
     */
    public array $simulate_concurrent_ids = [];

    /** Columns present in the fake table. */
    private array $columns = [
        'id', 'keyword', 'intent_type', 'entity_type', 'entity_id',
        'status', 'target_type', 'sources', 'updated_at',
    ];

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct( array $rows ) {
        foreach ( $rows as $row ) {
            $this->rows[ (int) $row['id'] ] = $row;
        }
    }

    // ── wpdb method stubs ──────────────────────────────────────────────────

    public function prepare( string $sql, ...$args ): string {
        // Flatten single-array arg (WP passes args as spread or single array).
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback(
            '/%[sdf]/',
            static function () use ( $args, &$i ) {
                $value = $args[ $i++ ] ?? '';
                return is_string( $value )
                    ? "'" . addslashes( $value ) . "'"
                    : (string) $value;
            },
            $sql
        );
    }

    public function get_var( string $sql ): ?string {
        $this->queries[] = $sql;
        // SHOW TABLES LIKE → return the table name so table_exists() passes.
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
            return $this->prefix . 'tmw_keyword_candidates';
        }
        return null;
    }

    /** @return array<int,array<string,string>> */
    public function get_results( string $sql, string $output = 'OBJECT' ): array {
        $this->queries[] = $sql;

        // SHOW COLUMNS → return column definitions.
        if ( false !== stripos( $sql, 'SHOW COLUMNS FROM' ) ) {
            return array_map(
                static fn( string $field ): array => [ 'Field' => $field ],
                $this->columns
            );
        }

        // SELECT rows — return all rows (LIMIT/OFFSET ignored for simplicity).
        if ( false !== stripos( $sql, 'SELECT' ) ) {
            return array_values( $this->rows );
        }

        return [];
    }

    /**
     * @param string              $table
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     * @return int|false  1 = success, 0 = concurrent (entity_id no longer 0), false = db error
     */
    public function update( string $table, array $data, array $where ) {
        $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        $id = (int) ( $where['id'] ?? 0 );

        // Simulate concurrent update: another process already changed entity_id.
        if ( in_array( $id, $this->simulate_concurrent_ids, true ) ) {
            // Do NOT merge data into rows (the real DB row was already updated
            // by the concurrent process, making WHERE entity_id=0 non-matching).
            return 0;
        }

        if ( isset( $this->rows[ $id ] ) ) {
            $this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
        }
        return 1;
    }

    /** Required by table_exists() → esc_like. */
    public function esc_like( string $text ): string {
        return addcslashes( $text, '_%\\' );
    }
}
