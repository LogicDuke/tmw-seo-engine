<?php
/**
 * PR-C — KeywordAssignmentRepository behavioral tests.
 *
 * Drives the REAL repository against a transactional in-memory $wpdb that
 * parses the repository's queries, buffers writes inside START TRANSACTION /
 * COMMIT / ROLLBACK, and records every write target — so single-primary
 * enforcement, rollback, identity dedupe, and candidate isolation are all
 * proven behaviorally without a live database.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentRepository;

require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php';

/**
 * In-memory wpdb for the assignments table with transaction semantics.
 */
final class AssignmentStateWpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> committed state */
    public array $assignments = [];
    /** @var array<int,array<string,mixed>> candidate rows (must never change) */
    public array $candidates = [];
    /** @var array<int,array{op:string,table:string}> */
    public array $writes = [];
    public string $last_error = '';
    public bool $table_present = true;
    public bool $fail_verification = false;

    /** @var array<int,array<string,mixed>>|null transaction buffer */
    private ?array $txn = null;
    private int $next_id = 1;

    public function prepare( string $sql, ...$args ): string {
        $i = 0;
        return (string) preg_replace_callback( '/%[sdf]/', function () use ( $args, &$i ) {
            $value = $args[ $i++ ] ?? '';
            return is_string( $value ) ? "'" . addslashes( $value ) . "'" : (string) $value;
        }, $sql );
    }
    public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }

    /** @return array<int,array<string,mixed>> */
    private function &state(): array {
        if ( null !== $this->txn ) { return $this->txn; }
        return $this->assignments;
    }

    public function query( string $sql ) {
        if ( 'START TRANSACTION' === $sql ) { $this->txn = $this->assignments; return 1; }
        if ( 'COMMIT' === $sql ) {
            if ( null !== $this->txn ) { $this->assignments = $this->txn; $this->txn = null; }
            return 1;
        }
        if ( 'ROLLBACK' === $sql ) { $this->txn = null; return 1; }
        // Demotion UPDATE inside set_primary_owner().
        if ( preg_match( "/UPDATE .*tmw_keyword_assignments SET canonical_owner = 0, role = 'secondary'.*keyword_candidate_id = (\d+) AND id != (\d+)/s", $sql, $m ) ) {
            $this->writes[] = [ 'op' => 'update', 'table' => 'assignments' ];
            $state = &$this->state();
            $count = 0;
            foreach ( $state as $id => $row ) {
                if ( (int) $row['keyword_candidate_id'] === (int) $m[1] && $id !== (int) $m[2]
                    && ( 1 === (int) $row['canonical_owner'] || 'primary' === (string) $row['role'] ) ) {
                    $state[ $id ]['canonical_owner'] = 0;
                    $state[ $id ]['role'] = 'secondary';
                    $count++;
                }
            }
            return $count;
        }
        $this->writes[] = [ 'op' => 'query', 'table' => $sql ];
        return 0;
    }

    public function get_var( string $sql ) {
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
            $clean = str_replace( '\\', '', $sql );
            if ( $this->table_present && false !== stripos( $clean, 'tmw_keyword_assignments' ) ) {
                return 'wp_tmw_keyword_assignments';
            }
            return null;
        }
        $state = $this->state();
        if ( preg_match( "/SELECT id FROM .* WHERE assignment_key = '([^']+)'/", $sql, $m ) ) {
            foreach ( $state as $id => $row ) {
                if ( (string) $row['assignment_key'] === $m[1] ) { return (string) $id; }
            }
            return null;
        }
        if ( preg_match( '/SELECT COUNT\(\*\) FROM .* WHERE keyword_candidate_id = (\d+) AND canonical_owner = 1 AND role = \'primary\'/', $sql, $m ) ) {
            if ( $this->fail_verification ) { return '2'; }
            $count = 0;
            foreach ( $state as $row ) {
                if ( (int) $row['keyword_candidate_id'] === (int) $m[1] && 1 === (int) $row['canonical_owner'] && 'primary' === (string) $row['role'] ) { $count++; }
            }
            return (string) $count;
        }
        if ( preg_match( '/SELECT COUNT\(\*\) FROM .* WHERE keyword_candidate_id = (\d+) AND id != (\d+)/', $sql, $m ) ) {
            $count = 0;
            foreach ( $state as $id => $row ) {
                if ( (int) $row['keyword_candidate_id'] === (int) $m[1] && $id !== (int) $m[2] ) { $count++; }
            }
            return (string) $count;
        }
        if ( preg_match( '/SELECT COUNT\(\*\) FROM .* WHERE keyword_candidate_id = (\d+)/', $sql, $m ) ) {
            $count = 0;
            foreach ( $state as $row ) {
                if ( (int) $row['keyword_candidate_id'] === (int) $m[1] ) { $count++; }
            }
            return (string) $count;
        }
        return null;
    }

    public function get_row( string $sql, string $output = 'OBJECT' ) {
        $state = $this->state();
        if ( preg_match( "/WHERE assignment_key = '([^']+)' LIMIT 1/", $sql, $m ) ) {
            foreach ( $state as $row ) {
                if ( (string) $row['assignment_key'] === $m[1] ) { return $row; }
            }
            return null;
        }
        if ( preg_match( '/WHERE id = (\d+) LIMIT 1/', $sql, $m ) ) {
            return $state[ (int) $m[1] ] ?? null;
        }
        if ( preg_match( "/WHERE keyword_candidate_id = (\d+) AND role = 'primary' AND canonical_owner = 1 AND status IN \('approved','review_required'\)/", $sql, $m ) ) {
            foreach ( $state as $row ) {
                if ( (int) $row['keyword_candidate_id'] === (int) $m[1] && 'primary' === (string) $row['role'] && 1 === (int) $row['canonical_owner'] && in_array( (string) $row['status'], [ 'approved', 'review_required' ], true ) ) {
                    return $row;
                }
            }
            return null;
        }
        return null;
    }

    public function get_results( string $sql, string $output = 'OBJECT' ): array {
        $state = $this->state();
        if ( preg_match( '/WHERE keyword_candidate_id = (\d+) AND role = \'secondary\'/', $sql, $m ) ) {
            return array_values( array_filter( $state, fn ( $row ) => (int) $row['keyword_candidate_id'] === (int) $m[1] && 'secondary' === (string) $row['role'] ) );
        }
        if ( preg_match( '/WHERE keyword_candidate_id = (\d+)( FOR UPDATE)?/', $sql, $m ) ) {
            return array_values( array_filter( $state, fn ( $row ) => (int) $row['keyword_candidate_id'] === (int) $m[1] ) );
        }
        if ( preg_match( "/WHERE pool = '([^']*)' AND page_type = '([^']*)' AND target_key = '([^']*)'/", $sql, $m ) ) {
            return array_values( array_filter( $state, fn ( $row ) => $row['pool'] === $m[1] && $row['page_type'] === $m[2] && $row['target_key'] === $m[3] ) );
        }
        if ( preg_match( "/WHERE pool = '([^']*)' AND page_type = '([^']*)' AND target_type = '([^']*)' AND target_id = (\d+)/", $sql, $m ) ) {
            return array_values( array_filter( $state, fn ( $row ) => $row['pool'] === $m[1] && $row['page_type'] === $m[2] && $row['target_type'] === $m[3] && (int) $row['target_id'] === (int) $m[4] ) );
        }
        return [];
    }

    public function insert( string $table, array $data, $format = null ) {
        $this->writes[] = [ 'op' => 'insert', 'table' => $table ];
        if ( false === stripos( $table, 'tmw_keyword_assignments' ) ) { return false; }
        $state = &$this->state();
        $id = $this->next_id++;
        $data['id'] = $id;
        $state[ $id ] = $data;
        $this->insert_id = $id;
        return 1;
    }

    public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
        $this->writes[] = [ 'op' => 'update', 'table' => $table ];
        if ( false !== stripos( $table, 'tmw_keyword_candidates' ) ) {
            // Candidate mutations are forbidden in PR-C tests; record and refuse.
            $this->writes[] = [ 'op' => 'CANDIDATE_MUTATION', 'table' => $table ];
            return false;
        }
        $state = &$this->state();
        $id = (int) ( $where['id'] ?? 0 );
        if ( ! isset( $state[ $id ] ) ) { return 0; }
        $state[ $id ] = array_merge( $state[ $id ], $data );
        return 1;
    }

    public function delete( string $table, array $where, $where_format = null ) {
        $this->writes[] = [ 'op' => 'delete', 'table' => $table ];
        $state = &$this->state();
        $id = (int) ( $where['id'] ?? 0 );
        if ( isset( $state[ $id ] ) ) { unset( $state[ $id ] ); return 1; }
        return 0;
    }
}

final class KeywordAssignmentRepositoryTest extends TestCase {

    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    private function wpdb(): AssignmentStateWpdb {
        $wpdb = new AssignmentStateWpdb();
        $wpdb->candidates = [ 42 => [ 'id' => 42, 'keyword' => 'alpha generic phrase', 'status' => 'approved' ] ];
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    /** @return array<string,mixed> */
    private function payload( int $candidate_id, array $extra = [] ): array {
        return array_merge( [
            'keyword_candidate_id' => $candidate_id,
            'pool'                 => 'category',
            'page_type'            => 'tmw_category_page',
            'target_type'          => 'tmw_category_page',
            'target_id'            => 501,
            'role'                 => 'secondary',
            'status'               => 'review_required',
        ], $extra );
    }

    // ── 8. Create one primary assignment ──────────────────────────────────

    public function test_create_primary_assignment(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $result = $repo->create_assignment( $this->payload( 42, [ 'role' => 'primary', 'status' => 'approved', 'canonical_owner' => 1 ] ) );

        $this->assertTrue( $result['ok'] );
        $owner = $repo->find_primary_owner( 42 );
        $this->assertNotNull( $owner );
        $this->assertSame( 'primary', $owner['role'] );
        $this->assertSame( 1, (int) $owner['canonical_owner'] );
        $this->assertSame( 'tmw_category_page:501', $owner['target_key'] );
    }

    // ── 9 & 20 & 21. Multiple secondaries, two targets, cross-pool coexistence ──

    public function test_multiple_secondaries_two_targets_and_pools_coexist(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $this->assertTrue( $repo->create_assignment( $this->payload( 42, [ 'target_id' => 501 ] ) )['ok'] );
        $this->assertTrue( $repo->create_assignment( $this->payload( 42, [ 'target_id' => 502 ] ) )['ok'] );
        $this->assertTrue( $repo->create_assignment( $this->payload( 42, [ 'pool' => 'model', 'page_type' => 'model', 'target_type' => 'model', 'target_id' => 900 ] ) )['ok'] );

        $this->assertSame( 3, $repo->count_assignments_for_candidate( 42 ) );
        $this->assertCount( 3, $repo->find_secondary_assignments( 42 ) );
        $this->assertCount( 1, $repo->find_assignments_for_target( 'category', 'tmw_category_page', 'tmw_category_page', 502 ) );
        $this->assertCount( 1, $repo->find_assignments_for_target( 'model', 'model', 'model', 900 ) );
    }

    // ── 10 & 22. Duplicate identity prevented / upsert deterministic ──────

    public function test_duplicate_identity_is_prevented_and_upsert_collapses(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $first = $repo->create_assignment( $this->payload( 42, [ 'source_batch_id' => 1 ] ) );
        $dup   = $repo->create_assignment( $this->payload( 42, [ 'source_batch_id' => 2 ] ) );
        $this->assertTrue( $first['ok'] );
        $this->assertFalse( $dup['ok'] );
        $this->assertSame( 'assignment_identity_exists', $dup['error'] );

        // Same target through another batch: upsert updates the one row.
        $upsert = $repo->upsert_assignment( $this->payload( 42, [ 'source_batch_id' => 3, 'status' => 'approved' ] ) );
        $this->assertTrue( $upsert['ok'] );
        $this->assertSame( 'updated', $upsert['action'] );
        $this->assertSame( (int) $first['id'], (int) $upsert['id'] );
        $this->assertSame( 1, $repo->count_assignments_for_candidate( 42 ) );
        $this->assertSame( 'approved', $wpdb->assignments[ (int) $first['id'] ]['status'] );
    }

    // ── 11. Two active primaries prevented ────────────────────────────────

    public function test_two_active_primary_owners_are_prevented(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $this->assertTrue( $repo->create_assignment( $this->payload( 42, [ 'role' => 'primary', 'status' => 'approved', 'canonical_owner' => 1, 'target_id' => 501 ] ) )['ok'] );
        $second = $repo->create_assignment( $this->payload( 42, [ 'role' => 'primary', 'status' => 'approved', 'canonical_owner' => 1, 'target_id' => 502 ] ) );

        $this->assertFalse( $second['ok'] );
        $this->assertSame( 'active_primary_owner_already_exists', $second['error'] );
    }

    // ── 12. Switching primary leaves exactly one canonical owner ──────────

    public function test_set_primary_owner_switches_to_exactly_one_owner(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $a = $repo->create_assignment( $this->payload( 42, [ 'role' => 'primary', 'status' => 'approved', 'canonical_owner' => 1, 'target_id' => 501 ] ) );
        $b = $repo->create_assignment( $this->payload( 42, [ 'target_id' => 502, 'status' => 'approved' ] ) );

        $this->assertTrue( $repo->set_primary_owner( (int) $b['id'] ) );

        $owners = array_filter( $wpdb->assignments, fn ( $row ) => 1 === (int) $row['canonical_owner'] && 'primary' === (string) $row['role'] );
        $this->assertCount( 1, $owners );
        $this->assertSame( (int) $b['id'], (int) array_values( $owners )[0]['id'] );
        $this->assertSame( 'secondary', $wpdb->assignments[ (int) $a['id'] ]['role'], 'Previous owner demoted to secondary.' );
        $this->assertSame( 0, (int) $wpdb->assignments[ (int) $a['id'] ]['canonical_owner'] );
    }

    // ── 13 & 14. Rank Math forbidden for rejected/excluded ────────────────

    public function test_rejected_assignment_cannot_be_active_in_rank_math(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $invalid = $repo->create_assignment( $this->payload( 42, [ 'status' => 'rejected', 'active_in_rank_math' => 1 ] ) );
        $this->assertFalse( $invalid['ok'] );
        $this->assertSame( 'rank_math_activation_forbidden_for_role_or_status', $invalid['error'] );

        // A status transition to rejected clears the flag on the same row.
        $created = $repo->create_assignment( $this->payload( 42, [ 'status' => 'approved', 'active_in_rank_math' => 1 ] ) );
        $this->assertTrue( $repo->update_assignment_status( (int) $created['id'], 'rejected', 'operator_rejected' ) );
        $this->assertSame( 0, (int) $wpdb->assignments[ (int) $created['id'] ]['active_in_rank_math'] );
    }

    public function test_excluded_assignment_cannot_be_active_in_rank_math(): void {
        $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $invalid = $repo->create_assignment( $this->payload( 42, [ 'role' => 'excluded', 'active_in_rank_math' => 1 ] ) );

        $this->assertFalse( $invalid['ok'] );
        $this->assertSame( 'rank_math_activation_forbidden_for_role_or_status', $invalid['error'] );
    }

    // ── 15. Secondary cannot be canonical owner ───────────────────────────

    public function test_secondary_assignment_cannot_be_canonical_owner(): void {
        $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $invalid = $repo->create_assignment( $this->payload( 42, [ 'role' => 'secondary', 'canonical_owner' => 1 ] ) );

        $this->assertFalse( $invalid['ok'] );
        $this->assertSame( 'canonical_owner_requires_primary_role', $invalid['error'] );
    }

    // ── 16 & 17. Lookups by candidate and by target ───────────────────────

    public function test_lookup_by_candidate_and_target(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();
        $repo->create_assignment( $this->payload( 42, [ 'target_id' => 501 ] ) );
        $repo->create_assignment( $this->payload( 42, [ 'pool' => 'model', 'page_type' => 'model', 'target_type' => 'global', 'target_id' => 0, 'target_key' => 'global-model-pool' ] ) );

        $this->assertCount( 2, $repo->find_assignments_for_candidate( 42 ) );
        $by_key = $repo->find_assignments_for_target( 'model', 'model', 'global', 0, 'global-model-pool' );
        $this->assertCount( 1, $by_key );
        $found = $repo->find_assignment( 42, [ 'pool' => 'category', 'page_type' => 'tmw_category_page', 'target_type' => 'tmw_category_page', 'target_id' => 501 ] );
        $this->assertNotNull( $found );
        $this->assertSame( 'tmw_category_page:501', $found['target_key'] );
    }

    // ── 18 & 19. Status updates isolated from candidate and siblings ──────

    public function test_status_update_does_not_touch_candidate_or_sibling(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();
        $a = $repo->create_assignment( $this->payload( 42, [ 'target_id' => 501 ] ) );
        $b = $repo->create_assignment( $this->payload( 42, [ 'target_id' => 502 ] ) );

        $this->assertTrue( $repo->update_assignment_status( (int) $a['id'], 'rejected', 'not_relevant_here' ) );

        $this->assertSame( 'rejected', $wpdb->assignments[ (int) $a['id'] ]['status'] );
        $this->assertSame( 'review_required', $wpdb->assignments[ (int) $b['id'] ]['status'], 'Sibling assignment untouched.' );
        $this->assertSame( 'approved', $wpdb->candidates[42]['status'], 'Candidate status untouched.' );
        foreach ( $wpdb->writes as $write ) {
            $this->assertNotSame( 'CANDIDATE_MUTATION', $write['op'], 'Candidate table must never be written.' );
        }
    }

    // ── 23. Batch deletion leaves assignments alone (static) ──────────────

    public function test_batch_delete_path_never_references_assignments(): void {
        $batch_repository = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php' );
        $this->assertStringNotContainsString( 'tmw_keyword_assignments', $batch_repository );
        $this->assertStringNotContainsString( 'KeywordAssignmentRepository', $batch_repository );
    }

    // ── 24. Missing table fails safe ──────────────────────────────────────

    public function test_missing_assignments_table_fails_safe(): void {
        $wpdb = $this->wpdb();
        $wpdb->table_present = false;
        $repo = new KeywordAssignmentRepository();

        $this->assertNull( $repo->find_by_id( 1 ) );
        $this->assertSame( [], $repo->find_assignments_for_candidate( 42 ) );
        $this->assertNull( $repo->find_primary_owner( 42 ) );
        $this->assertSame( 0, $repo->count_assignments_for_candidate( 42 ) );
        $create = $repo->create_assignment( $this->payload( 42 ) );
        $this->assertFalse( $create['ok'] );
        $this->assertSame( 'assignments_table_missing', $create['error'] );
        $this->assertFalse( $repo->set_primary_owner( 1 ) );
    }

    // ── 25. Rollback preserves previous primary ───────────────────────────

    public function test_failed_verification_rolls_back_and_preserves_previous_owner(): void {
        $wpdb = $this->wpdb();
        $repo = new KeywordAssignmentRepository();
        $a = $repo->create_assignment( $this->payload( 42, [ 'role' => 'primary', 'status' => 'approved', 'canonical_owner' => 1, 'target_id' => 501 ] ) );
        $b = $repo->create_assignment( $this->payload( 42, [ 'target_id' => 502, 'status' => 'approved' ] ) );

        $wpdb->fail_verification = true;
        $this->assertFalse( $repo->set_primary_owner( (int) $b['id'] ), 'Must fail closed when uniqueness cannot be verified.' );

        // Committed state unchanged: A is still the sole canonical primary.
        $this->assertSame( 'primary', $wpdb->assignments[ (int) $a['id'] ]['role'] );
        $this->assertSame( 1, (int) $wpdb->assignments[ (int) $a['id'] ]['canonical_owner'] );
        $this->assertSame( 'secondary', $wpdb->assignments[ (int) $b['id'] ]['role'] );
        $this->assertSame( 0, (int) $wpdb->assignments[ (int) $b['id'] ]['canonical_owner'] );
    }

    // ── 26. No category-specific hardcoding ───────────────────────────────

    public function test_no_category_specific_hardcoding_in_repository(): void {
        $source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php' );
        foreach ( [ 'Free Cam Chat', 'Live Cam Chat', 'live jasmin', 'livejasmin' ] as $forbidden ) {
            $this->assertFalse( stripos( $source, $forbidden ), 'Hardcoded audit example found: ' . $forbidden );
        }
    }

    // ── Validation edge: deterministic target identity required ───────────

    public function test_indeterminate_target_identity_is_rejected(): void {
        $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $invalid = $repo->create_assignment( $this->payload( 42, [ 'target_type' => 'global', 'target_id' => 0, 'target_key' => '' ] ) );

        $this->assertFalse( $invalid['ok'] );
        $this->assertSame( 'indeterminate_target_identity', $invalid['error'] );
    }

    public function test_unsupported_role_and_status_are_rejected(): void {
        $this->wpdb();
        $repo = new KeywordAssignmentRepository();

        $bad_role = $repo->create_assignment( $this->payload( 42, [ 'role' => 'owner' ] ) );
        $bad_status = $repo->create_assignment( $this->payload( 42, [ 'status' => 'maybe' ] ) );

        $this->assertSame( 'unsupported_role', $bad_role['error'] );
        $this->assertSame( 'unsupported_status', $bad_status['error'] );
    }
}
