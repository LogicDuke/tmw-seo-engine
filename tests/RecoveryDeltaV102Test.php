<?php
/**
 * PR-H v1.0.2 — delta regression tests.
 *
 * Written and observed FAILING against the applied v1.0.1 state; see
 * evidence/red-before-v1.0.2.txt. Warnings, notices and deprecations are
 * promoted to test failures.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

require_once __DIR__ . '/support/RecoveryFakeDb.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';

final class RecoveryDeltaV102Test extends TestCase {

    private RecoveryStore $store;
    private RecoveryFakeDb $db;
    private RecoveryFakeConnectionFactory $factory;

    protected function setUp(): void {
        set_error_handler( static function ( int $no, string $msg, string $file = '', int $line = 0 ): bool {
            if ( 0 === ( error_reporting() & $no ) ) { return false; }
            throw new \ErrorException( $msg, 0, $no, $file, $line );
        } );

        $this->store   = RecoveryStore::fresh( 'v102' );
        $this->db      = new RecoveryFakeDb( $this->store );
        $this->factory = new RecoveryFakeConnectionFactory( $this->db );

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function __call( string $name, array $args ) {
                throw new \RuntimeException( 'recovery must never use the primary $wpdb (' . $name . ')' );
            }
        };
        $GLOBALS['_tmw_test_options'] = [];
    }

    protected function tearDown(): void {
        restore_error_handler();
        if ( isset( $this->store ) ) { @unlink( $this->store->path ); }
    }

    private function repo(): Repo { return new Repo( $this->factory ); }

    /** @return array<string,mixed> */
    private function outcome( array $o = [] ): array {
        return array_merge( [
            'operation_key'  => 'manual_approval:row:900',
            'operation_type' => 'manual_approval',
            'row_id'         => 900,
            'batch_id'       => 70,
            'reason'         => 'rollback_failed',
            'evidence'       => [ 'state' => 'open' ],
        ], $o );
    }

    /** @return array<string,mixed> */
    private function decision( array $o = [] ): array {
        return array_merge( [
            'decision'          => 'acknowledged',
            'resolved_by'       => 42,
            'resolution_reason' => 'verified against committed state',
            'evidence'          => [],
        ], $o );
    }

    // ══ Item 1 — exact generation and payload verification ═══════════════

    /**
     * The race the prompt specifies: generation 1 is resolved, then reopened as
     * generation 2 and resolved again before generation 1's verification read.
     * The generation-1 request must not report success.
     */
    public function test_resolution_superseded_before_its_verification_read_does_not_report_success(): void {
        $repo = $this->repo();
        $first = $repo->record_unresolved_outcome( $this->outcome() );
        $this->assertSame( 1, (int) $first['generation'] );

        // The post-write read observes a NEWER, already-resolved generation.
        $this->db->post_write_row_override = [
            'operation_key'     => 'manual_approval:row:900',
            'operation_type'    => 'manual_approval',
            'row_id'            => 900,
            'batch_id'          => 70,
            'generation'        => 2,
            'state'             => 'resolved',
            'resolved_by'       => 99,
            'resolution_reason' => 'resolved by a later operator',
            'reason'            => 'reopened',
        ];

        $result = $repo->resolve_outcome( 'manual_approval:row:900', 1, $this->decision() );

        $this->assertFalse( (bool) $result['ok'], 'a superseded resolution must not report success' );
        $this->assertContains( (string) $result['status'], [ 'superseded_after_write', 'verification_failure' ] );
        if ( array_key_exists( 'current_generation', $result ) ) {
            $this->assertSame( 2, (int) $result['current_generation'], 'the current generation is reported where known' );
        }
        $this->assertNotSame( 1, (int) ( $result['generation'] ?? 0 ), 'must not claim generation 1 was resolved' );
    }

    public function test_wrong_resolver_after_write_is_not_verified(): void {
        $repo = $this->repo();
        $rec = $repo->record_unresolved_outcome( $this->outcome() );
        $this->db->post_write_row_override = [
            'operation_key' => 'manual_approval:row:900', 'operation_type' => 'manual_approval',
            'row_id' => 900, 'batch_id' => 70, 'generation' => (int) $rec['generation'],
            'state' => 'resolved', 'resolved_by' => 7, // not the requested 42
            'resolution_reason' => 'verified against committed state',
        ];
        $result = $repo->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'verification_failure', (string) $result['status'] );
    }

    public function test_wrong_resolution_reason_after_write_is_not_verified(): void {
        $repo = $this->repo();
        $rec = $repo->record_unresolved_outcome( $this->outcome() );
        $this->db->post_write_row_override = [
            'operation_key' => 'manual_approval:row:900', 'operation_type' => 'manual_approval',
            'row_id' => 900, 'batch_id' => 70, 'generation' => (int) $rec['generation'],
            'state' => 'resolved', 'resolved_by' => 42,
            'resolution_reason' => 'something entirely different',
        ];
        $result = $repo->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'verification_failure', (string) $result['status'] );
    }

    public function test_resolved_state_but_changed_identity_is_not_verified(): void {
        $repo = $this->repo();
        $rec = $repo->record_unresolved_outcome( $this->outcome() );
        $this->db->post_write_row_override = [
            'operation_key' => 'manual_approval:row:900', 'operation_type' => 'manual_approval',
            'row_id' => 901, // identity drifted
            'batch_id' => 70, 'generation' => (int) $rec['generation'],
            'state' => 'resolved', 'resolved_by' => 42,
            'resolution_reason' => 'verified against committed state',
        ];
        $result = $repo->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'verification_failure', (string) $result['status'] );
    }

    public function test_exact_resolution_succeeds_and_reports_its_own_generation(): void {
        $repo = $this->repo();
        $rec = $repo->record_unresolved_outcome( $this->outcome() );
        $result = $repo->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $this->assertTrue( (bool) $result['ok'], (string) $result['status'] );
        $this->assertSame( (int) $rec['generation'], (int) $result['generation'] );
        $this->assertSame( 42, (int) $result['row']['resolved_by'] );
        $this->assertSame( 'verified against committed state', (string) $result['row']['resolution_reason'] );
    }

    // ══ Item 2 — complete unique index shape ═════════════════════════════

    public function test_prefix_index_is_rejected(): void {
        $this->db->identity_index_sub_part = 100;
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
        $this->assertStringContainsString( 'prefix', strtolower( (string) $result['reason'] ) );
    }

    public function test_multi_column_identity_index_is_rejected(): void {
        $this->db->identity_extra_parts = [ [ 'Column_name' => 'row_id', 'Seq_in_index' => '2' ] ];
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'operation_key', (string) $result['reason'] );
    }

    public function test_duplicate_identity_index_rows_are_rejected(): void {
        $this->db->identity_extra_parts = [ [ 'Column_name' => 'operation_key', 'Seq_in_index' => '1' ] ];
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
    }

    public function test_wrong_sequence_position_is_rejected(): void {
        $this->db->identity_index_seq = 2;
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'sequence', strtolower( (string) $result['reason'] ) );
    }

    public function test_wrong_column_is_rejected(): void {
        $this->db->identity_index_column = 'row_id';
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'operation_key', (string) $result['reason'] );
    }

    public function test_non_unique_index_is_rejected(): void {
        $this->db->identity_index_non_unique = true;
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'unique', strtolower( (string) $result['reason'] ) );
    }

    public function test_valid_full_column_unique_index_is_accepted(): void {
        $this->db->identity_index_sub_part = null;
        $this->db->identity_index_seq = 1;
        $this->db->identity_extra_parts = [];
        $result = $this->repo()->verify_schema();
        $this->assertTrue( (bool) $result['ok'], (string) $result['reason'] );
    }

    public function test_empty_string_sub_part_is_accepted_as_full_column(): void {
        // Some drivers report '' rather than NULL for a full-column index part.
        $this->db->identity_index_sub_part = '';
        $result = $this->repo()->verify_schema();
        $this->assertTrue( (bool) $result['ok'], (string) $result['reason'] );
    }

    // ══ Item 3 — factory failure status is preserved ═════════════════════

    public function test_repository_preserves_a_connection_policy_failure_from_the_factory(): void {
        $factory = new class {
            public int $opens = 0;
            public int $closes = 0;
            public function open(): array {
                $this->opens++;
                return [ 'ok' => false, 'status' => 'connection_policy_failure', 'db' => null, 'error' => 'policy could not be applied' ];
            }
            public function close( $db ): void { $this->closes++; }
        };
        $repo = new Repo( $factory );

        foreach ( [
            'record' => $repo->record_unresolved_outcome( $this->outcome() ),
            'find'   => $repo->find_unresolved_outcome( 'manual_approval:row:900' ),
            'list'   => $repo->list_unresolved_outcomes(),
            'verify' => $repo->verify_schema(),
        ] as $label => $result ) {
            $this->assertFalse( (bool) $result['ok'], $label );
            $this->assertSame( 'connection_policy_failure', (string) $result['status'], $label . ' must preserve the factory status' );
        }
    }

    public function test_repository_preserves_an_unknown_future_connection_status(): void {
        $factory = new class {
            public function open(): array {
                return [ 'ok' => false, 'status' => 'connection_quota_exhausted', 'db' => null, 'error' => 'no spare connections' ];
            }
            public function close( $db ): void {}
        };
        $result = ( new Repo( $factory ) )->verify_schema();
        $this->assertSame( 'connection_quota_exhausted', (string) $result['status'] );
    }

    // ══ Item 4 — the session policy is applied exactly once ══════════════

    public function test_session_policy_is_applied_exactly_once_per_operation(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        foreach ( \TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeConnection::SESSION_POLICY as $statement ) {
            $applied = array_filter( $this->db->statements, static fn( string $s ): bool => $s === $statement );
            $this->assertCount( 1, $applied, 'exactly one application of: ' . $statement );
        }
    }

    public function test_session_policy_precedes_every_data_and_schema_query(): void {
        $this->repo()->find_unresolved_outcome( 'manual_approval:row:900' );
        $policyPositions = [];
        foreach ( $this->db->statements as $i => $statement ) {
            if ( 1 === preg_match( '/^SET SESSION/i', $statement ) ) { $policyPositions[] = $i; }
        }
        $firstQuery = null;
        foreach ( $this->db->statements as $i => $statement ) {
            if ( 1 !== preg_match( '/^SET SESSION/i', $statement ) ) { $firstQuery = $i; break; }
        }
        $this->assertGreaterThan( 0, count( $policyPositions ) );
        $this->assertNotNull( $firstQuery, 'the operation must issue at least one real query' );
        $this->assertLessThan( $firstQuery, max( $policyPositions ), 'the policy must be fully applied before the first query' );
    }
}
