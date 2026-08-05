<?php
/**
 * PR-H — behavioral tests for the independent transaction-outcome recovery
 * subsystem.
 *
 * Written and observed FAILING against the unchanged repository; see
 * evidence/red-before-prh.txt.
 *
 * Every test drives real behaviour. PHP warnings, notices and deprecations are
 * promoted to failures in setUp — an undefined variable is a test failure here,
 * which is exactly the class of defect that a string-scanning guard missed in
 * an earlier PR.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

require_once __DIR__ . '/support/RecoveryFakeDb.php';
require_once __DIR__ . '/../includes/db/class-schema.php';
if ( file_exists( __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php' ) ) {
    require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';
}

if ( file_exists( __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php' ) ) {
    require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';
}

final class UnresolvedTransactionOutcomeRepositoryTest extends TestCase {

    private RecoveryStore $store;
    private RecoveryFakeDb $db;
    private RecoveryFakeConnectionFactory $factory;

    protected function setUp(): void {
        // Promote warnings / notices / deprecations to test failures.
        set_error_handler( static function ( int $no, string $msg, string $file = '', int $line = 0 ): bool {
            // Respect the @-operator / current mask so suppressed housekeeping
            // calls stay suppressed, while every real warning, notice and
            // deprecation raised by production code becomes a test failure.
            if ( 0 === ( error_reporting() & $no ) ) { return false; }
            throw new \ErrorException( $msg, 0, $no, $file, $line );
        } );

        $this->store   = RecoveryStore::fresh();
        $this->db      = new RecoveryFakeDb( $this->store );
        $this->factory = new RecoveryFakeConnectionFactory( $this->db );

        // The primary connection is deliberately hostile: any use of it by the
        // recovery subsystem is a bug, so it throws.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function __call( string $name, array $args ) {
                throw new \RuntimeException( 'recovery subsystem must never touch the primary $wpdb (called ' . $name . ')' );
            }
        };
        $GLOBALS['_tmw_test_options'] = [];
    }

    protected function tearDown(): void {
        restore_error_handler();
        if ( isset( $this->store ) ) { @unlink( $this->store->path ); }
    }

    private function repo(): Repo {
        return new Repo( $this->factory );
    }

    /** @return array<string,mixed> */
    private function outcome( array $overrides = [] ): array {
        return array_merge( [
            'operation_key'           => 'manual_approval:row:900',
            'operation_type'          => 'manual_approval',
            'row_id'                  => 900,
            'batch_id'                => 70,
            'expected_candidate_id'   => 10,
            'expected_assignment_key' => 'abc123',
            'correlation_id'          => 'corr-0001',
            'reason'                  => 'rollback_failed:assignment_insert',
            'evidence'                => [ 'connection_transaction_state' => 'open' ],
        ], $overrides );
    }

    // ══ Independent connection ═══════════════════════════════════════════

    public function test_records_marker_while_primary_wpdb_is_unusable(): void {
        // Any call to the primary $wpdb throws; a successful record proves the
        // recovery write did not touch it.
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );

        $this->assertTrue( (bool) $result['ok'], (string) ( $result['status'] ?? '' ) );
        $this->assertSame( 'ok', (string) $result['status'] );
        $this->assertGreaterThan( 0, (int) $result['generation'] );
        $this->assertSame( 'manual_approval:row:900', (string) $result['operation_key'] );
    }

    public function test_records_marker_while_primary_transaction_remains_open(): void {
        // Modelled by the primary connection being untouchable AND the recovery
        // store being written on its own connection.
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $found = $this->repo()->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertTrue( (bool) $found['ok'] );
        $this->assertTrue( (bool) $found['found'] );
        $this->assertSame( 'unresolved', (string) $found['row']['state'] );
    }

    public function test_connection_failure_is_distinct_and_fails_closed(): void {
        $this->factory->cannot_connect = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_failure', (string) $result['status'] );
    }

    public function test_connection_timeout_is_reported_as_connection_failure(): void {
        $this->factory->connect_timeout = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_failure', (string) $result['status'] );
    }

    public function test_credentials_never_appear_in_results(): void {
        $this->factory->cannot_connect = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $blob = (string) json_encode( $result );
        $this->assertStringNotContainsString( $this->factory->secret, $blob );
        $this->assertStringNotContainsString( 'password', strtolower( $blob ) );
    }

    public function test_connection_is_released_after_every_operation(): void {
        $repo = $this->repo();
        $repo->record_unresolved_outcome( $this->outcome() );
        $this->assertTrue( $this->db->closed, 'connection must be closed after recording' );
        $this->db->closed = false;
        $repo->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertTrue( $this->db->closed, 'connection must be closed after reading' );
        $this->assertSame( $this->factory->opens, $this->factory->closes, 'every open is matched by a close' );
    }

    public function test_session_lock_wait_timeout_is_set_on_the_recovery_connection(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $set = array_filter( $this->db->statements, static fn( string $s ): bool => 1 === preg_match( '/^SET SESSION.*innodb_lock_wait_timeout/i', $s ) );
        $this->assertGreaterThan( 0, count( $set ), 'a short session lock-wait timeout must be applied' );
    }

    // ══ Durability ═══════════════════════════════════════════════════════

    public function test_marker_is_visible_to_a_brand_new_service_instance(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );

        // Entirely new objects, including a new connection over the same store.
        $fresh_db      = new RecoveryFakeDb( new RecoveryStore( $this->store->path ) );
        $fresh_factory = new RecoveryFakeConnectionFactory( $fresh_db );
        $found = ( new Repo( $fresh_factory ) )->find_unresolved_outcome( 'manual_approval:row:900' );

        $this->assertTrue( (bool) $found['found'] );
        $this->assertSame( 900, (int) $found['row']['row_id'] );
    }

    public function test_blocking_outcome_is_discoverable_by_row_id(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertTrue( (bool) $blocking['ok'] );
        $this->assertTrue( (bool) $blocking['blocking'] );
        $this->assertSame( 'manual_approval:row:900', (string) $blocking['operation_key'] );
    }

    public function test_no_blocking_outcome_for_an_unrelated_row(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 901 ] );
        $this->assertTrue( (bool) $blocking['ok'] );
        $this->assertFalse( (bool) $blocking['blocking'] );
    }

    public function test_marker_survives_process_restart(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );

        // A genuinely separate PHP process reads the same durable store.
        $driver = __DIR__ . '/support/recovery-restart-driver.php';
        $cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $driver ) . ' '
            . escapeshellarg( $this->store->path ) . ' ' . escapeshellarg( 'manual_approval:row:900' ) . ' 2>&1';
        $out = (string) shell_exec( $cmd );
        if ( ! preg_match( '/__RESTART_PROBE__(.*?)__END__/s', $out, $m ) ) {
            $this->fail( 'restart driver produced no probe. Output: ' . substr( $out, 0, 600 ) );
        }
        $probe = json_decode( $m[1], true );
        $this->assertIsArray( $probe );
        $this->assertTrue( (bool) $probe['found'], 'marker must survive a process restart' );
        $this->assertTrue( (bool) $probe['blocking'] );
        $this->assertSame( 900, (int) $probe['row_id'] );
    }

    public function test_duplicate_recording_is_generation_safe_and_loses_nothing(): void {
        $first  = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $second = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'reason' => 'second attempt' ] ) );

        $this->assertTrue( (bool) $second['ok'] );
        $this->assertGreaterThan( (int) $first['generation'], (int) $second['generation'], 'generation must advance' );
        $found = $this->repo()->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertSame( 'second attempt', (string) $found['row']['reason'] );
    }

    public function test_concurrent_recording_of_different_operations_loses_neither(): void {
        $a = new Repo( new RecoveryFakeConnectionFactory( new RecoveryFakeDb( new RecoveryStore( $this->store->path ) ) ) );
        $b = new Repo( new RecoveryFakeConnectionFactory( new RecoveryFakeDb( new RecoveryStore( $this->store->path ) ) ) );
        $a->record_unresolved_outcome( $this->outcome( [ 'operation_key' => 'manual_approval:row:900', 'row_id' => 900 ] ) );
        $b->record_unresolved_outcome( $this->outcome( [ 'operation_key' => 'manual_approval:row:901', 'row_id' => 901 ] ) );

        $list = $this->repo()->list_unresolved_outcomes();
        $this->assertTrue( (bool) $list['ok'] );
        $this->assertCount( 2, $list['rows'], 'neither concurrent outcome may be lost' );
    }

    // ══ Schema ═══════════════════════════════════════════════════════════

    public function test_schema_verification_succeeds_on_a_healthy_table(): void {
        $result = $this->repo()->verify_schema();
        $this->assertTrue( (bool) $result['ok'], (string) ( $result['reason'] ?? '' ) );
        $this->assertSame( 'ok', (string) $result['status'] );
    }

    public function test_schema_verification_detects_a_missing_table(): void {
        $this->db->table_missing = true;
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
        $this->assertStringContainsString( 'table', strtolower( (string) $result['reason'] ) );
    }

    public function test_schema_verification_detects_a_non_innodb_engine(): void {
        $this->db->engine = 'MyISAM';
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
        $this->assertStringContainsString( 'engine', strtolower( (string) $result['reason'] ) );
    }

    public function test_schema_verification_detects_a_missing_column(): void {
        $this->db->missing_columns = [ 'generation' ];
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'generation', (string) $result['reason'] );
    }

    public function test_schema_verification_detects_a_missing_unique_index(): void {
        $this->db->missing_indexes = [ 'operation_identity' ];
        $result = $this->repo()->verify_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'operation_identity', (string) $result['reason'] );
    }

    /**
     * v1.0.1: the RUNTIME reports schema failure structurally and logs it; it
     * must not write an option, because that would go through the primary
     * connection. Operator-visible persistence belongs to the installation
     * path and is covered by RecoverySchemaInstallationTest.
     */
    public function test_schema_failure_is_reported_structurally_without_touching_options(): void {
        $this->db->engine = 'MyISAM';
        $before = $GLOBALS['_tmw_test_options'];
        $result = $this->repo()->verify_schema();

        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
        $this->assertStringContainsString( 'engine', strtolower( (string) $result['reason'] ) );
        $this->assertSame( $before, $GLOBALS['_tmw_test_options'], 'the runtime must not write options' );
    }

    public function test_recording_refuses_when_the_schema_cannot_be_verified(): void {
        $this->db->table_missing = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
    }

    // ══ Write / verification failures ════════════════════════════════════

    public function test_write_failure_is_reported_and_never_claimed_as_success(): void {
        $this->db->fail_writes = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'write_failure', (string) $result['status'] );
    }

    public function test_lock_timeout_is_reported_distinctly(): void {
        $this->db->lock_timeout = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'lock_timeout', (string) $result['status'] );
    }

    public function test_unverified_write_is_not_reported_as_success(): void {
        $this->db->verification_read_blind = true;
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'verification_failure', (string) $result['status'] );
    }

    public function test_read_failure_is_never_reported_as_absence(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->db->fail_reads = true;
        $found = $this->repo()->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertFalse( (bool) $found['ok'] );
        $this->assertNotSame( 'not_found', (string) $found['status'] );
        $this->assertFalse( (bool) $found['found'], 'found must not be asserted on a failed read' );
    }

    public function test_missing_marker_is_reported_as_not_found(): void {
        $found = $this->repo()->find_unresolved_outcome( 'manual_approval:row:404' );
        $this->assertTrue( (bool) $found['ok'] );
        $this->assertFalse( (bool) $found['found'] );
        $this->assertSame( 'not_found', (string) $found['status'] );
    }

    public function test_unreadable_store_is_blocking_for_callers_requiring_certainty(): void {
        $this->db->fail_reads = true;
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertFalse( (bool) $blocking['ok'] );
        $this->assertTrue( (bool) $blocking['blocking'], 'an unreadable recovery store must itself block' );
    }

    public function test_list_reports_failure_rather_than_zero_outcomes(): void {
        $this->db->fail_reads = true;
        $list = $this->repo()->list_unresolved_outcomes();
        $this->assertFalse( (bool) $list['ok'] );
        $this->assertNotSame( 'ok', (string) $list['status'] );
    }

    // ══ Generation and resolution ════════════════════════════════════════

    /** @return array<string,mixed> */
    private function decision( array $o = [] ): array {
        return array_merge( [
            'decision'          => 'acknowledged',
            'resolved_by'       => 42,
            'resolution_reason' => 'verified by operator against committed state',
            'evidence'          => [ 'checked' => true ],
        ], $o );
    }

    public function test_correct_generation_resolves(): void {
        $rec = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $out = $this->repo()->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $this->assertTrue( (bool) $out['ok'], (string) ( $out['status'] ?? '' ) );
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertFalse( (bool) $blocking['blocking'] );
    }

    public function test_stale_generation_is_refused(): void {
        $first = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $stale = (int) $first['generation'];
        $this->repo()->record_unresolved_outcome( $this->outcome( [ 'reason' => 'newer' ] ) );

        $out = $this->repo()->resolve_outcome( 'manual_approval:row:900', $stale, $this->decision() );
        $this->assertFalse( (bool) $out['ok'] );
        $this->assertSame( 'stale_generation', (string) $out['status'] );
    }

    public function test_newer_marker_survives_a_stale_resolution_attempt(): void {
        $first = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->repo()->record_unresolved_outcome( $this->outcome( [ 'reason' => 'newer' ] ) );
        $this->repo()->resolve_outcome( 'manual_approval:row:900', (int) $first['generation'], $this->decision() );

        $found = $this->repo()->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertTrue( (bool) $found['found'], 'the newer marker must survive' );
        $this->assertSame( 'newer', (string) $found['row']['reason'] );
        $this->assertSame( 'unresolved', (string) $found['row']['state'] );
    }

    public function test_resolution_requires_a_resolver_identity(): void {
        $rec = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $out = $this->repo()->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision( [ 'resolved_by' => 0 ] ) );
        $this->assertFalse( (bool) $out['ok'] );
        $this->assertSame( 'invalid_resolution', (string) $out['status'] );
    }

    public function test_resolution_requires_a_resolution_reason(): void {
        $rec = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $out = $this->repo()->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision( [ 'resolution_reason' => '' ] ) );
        $this->assertFalse( (bool) $out['ok'] );
        $this->assertSame( 'invalid_resolution', (string) $out['status'] );
    }

    public function test_resolution_records_resolver_and_reason(): void {
        $rec = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->repo()->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $raw = ( new RecoveryFakeDb( new RecoveryStore( $this->store->path ) ) );
        $row = $raw->get_row( "SELECT * FROM wp_tmw_unresolved_transaction_outcomes WHERE operation_key = 'manual_approval:row:900' LIMIT 1", ARRAY_A );
        $this->assertIsArray( $row );
        $this->assertSame( 'resolved', (string) $row['state'] );
        $this->assertSame( 42, (int) $row['resolved_by'] );
        $this->assertNotSame( '', (string) $row['resolution_reason'] );
        $this->assertNotSame( '', (string) $row['resolved_at'] );
    }

    public function test_resolution_of_a_missing_operation_is_not_found(): void {
        $out = $this->repo()->resolve_outcome( 'manual_approval:row:404', 1, $this->decision() );
        $this->assertFalse( (bool) $out['ok'] );
        $this->assertSame( 'not_found', (string) $out['status'] );
    }

    public function test_resolution_write_failure_is_not_reported_as_resolved(): void {
        $rec = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->db->fail_writes = true;
        $out = $this->repo()->resolve_outcome( 'manual_approval:row:900', (int) $rec['generation'], $this->decision() );
        $this->assertFalse( (bool) $out['ok'] );
        $this->assertNotSame( 'ok', (string) $out['status'] );
    }

    // ══ Input hardening ══════════════════════════════════════════════════

    public function test_operation_keys_are_normalized(): void {
        $result = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'operation_key' => "  manual_approval:row:900\t " ] ) );
        $this->assertTrue( (bool) $result['ok'], (string) $result['status'] );
        $this->assertSame( 'manual_approval:row:900', (string) $result['operation_key'] );
        $this->assertLessThanOrEqual( 191, strlen( (string) $result['operation_key'] ) );
    }

    /** v1.0.1: truncation could collide two operations, so oversize is refused. */
    public function test_overlength_operation_key_is_rejected_not_truncated(): void {
        $result = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'operation_key' => str_repeat( 'x', 400 ) ] ) );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'invalid_operation_key', (string) $result['status'] );
    }

    public function test_empty_operation_key_fails_closed(): void {
        $result = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'operation_key' => '   ' ] ) );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'invalid_operation_key', (string) $result['status'] );
    }

    public function test_unencodable_evidence_fails_closed(): void {
        $result = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'evidence' => [ 'bad' => "\xB1\x31" ] ] ) );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'invalid_evidence', (string) $result['status'] );
    }
}
