<?php
/**
 * PR-H v1.0.1 — delta regression tests.
 *
 * Written and observed FAILING against the applied v1.0.0 state; see
 * evidence/red-before-v101.txt.
 *
 * Warnings, notices and deprecations are promoted to test failures.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

require_once __DIR__ . '/support/RecoveryFakeDb.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';

final class RecoveryDeltaV101Test extends TestCase {

    private RecoveryStore $store;
    private RecoveryFakeDb $db;
    private RecoveryFakeConnectionFactory $factory;

    protected function setUp(): void {
        set_error_handler( static function ( int $no, string $msg, string $file = '', int $line = 0 ): bool {
            if ( 0 === ( error_reporting() & $no ) ) { return false; }
            throw new \ErrorException( $msg, 0, $no, $file, $line );
        } );

        $this->store   = RecoveryStore::fresh( 'v101' );
        $this->db      = new RecoveryFakeDb( $this->store );
        $this->factory = new RecoveryFakeConnectionFactory( $this->db );

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function __call( string $name, array $args ) {
                throw new \RuntimeException( 'recovery must never use the primary $wpdb (' . $name . ')' );
            }
        };
        $GLOBALS['_tmw_test_options'] = [];
        // Any WordPress option call made by the recovery runtime is a failure.
        $GLOBALS['_tmw_option_calls'] = [];
    }

    protected function tearDown(): void {
        restore_error_handler();
        if ( isset( $this->store ) ) { @unlink( $this->store->path ); }
    }

    private function repo(): Repo { return new Repo( $this->factory ); }

    /** @return array<string,mixed> */
    private function outcome( array $o = [] ): array {
        return array_merge( [
            'operation_key'         => 'manual_approval:row:900',
            'operation_type'        => 'manual_approval',
            'row_id'                => 900,
            'batch_id'              => 70,
            'expected_candidate_id' => 10,
            'reason'                => 'rollback_failed',
            'evidence'              => [ 'state' => 'open' ],
        ], $o );
    }

    // ══ Item 1 — no primary-connection access in the recovery runtime ═════

    public function test_recovery_runtime_contains_no_wordpress_option_calls(): void {
        foreach ( [
            '/../includes/recovery/class-unresolved-transaction-outcome-repository.php',
            '/../includes/recovery/class-unresolved-transaction-outcome-connection.php',
        ] as $rel ) {
            $src = (string) file_get_contents( __DIR__ . $rel );
            foreach ( [ 'get_option', 'update_option', 'delete_option', 'add_option' ] as $call ) {
                $this->assertStringNotContainsString( $call . '(', $src, $rel . ' must not use ' . $call . '()' );
            }
        }
    }

    public function test_schema_verification_failure_writes_no_option(): void {
        $this->db->engine = 'MyISAM';
        $before = $GLOBALS['_tmw_test_options'];
        $result = $this->repo()->verify_schema();

        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
        $this->assertSame( $before, $GLOBALS['_tmw_test_options'], 'runtime verification must not touch the options table' );
    }

    public function test_schema_verification_success_writes_no_option(): void {
        $before = $GLOBALS['_tmw_test_options'];
        $result = $this->repo()->verify_schema();
        $this->assertTrue( (bool) $result['ok'] );
        $this->assertSame( $before, $GLOBALS['_tmw_test_options'], 'success must not clear an option either' );
    }

    // ══ Item 2 — timeout policy is enforced ══════════════════════════════

    public function test_innodb_lock_timeout_failure_is_a_connection_policy_failure(): void {
        $this->db->fail_session_statement = 'innodb_lock_wait_timeout';
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_policy_failure', (string) $result['status'] );
    }

    public function test_metadata_lock_timeout_failure_is_a_connection_policy_failure(): void {
        $this->db->fail_session_statement = 'SET SESSION lock_wait_timeout';
        $result = $this->repo()->record_unresolved_outcome( $this->outcome() );
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_policy_failure', (string) $result['status'] );
    }

    public function test_timeout_policy_applies_both_statements_on_success(): void {
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $innodb = array_filter( $this->db->statements, static fn( string $s ): bool => 1 === preg_match( '/SET SESSION innodb_lock_wait_timeout = 3/i', $s ) );
        $meta   = array_filter( $this->db->statements, static fn( string $s ): bool => 1 === preg_match( '/SET SESSION lock_wait_timeout = 3/i', $s ) );
        $this->assertGreaterThan( 0, count( $innodb ), 'InnoDB row-lock timeout must be applied' );
        $this->assertGreaterThan( 0, count( $meta ), 'metadata lock timeout must be applied' );
    }

    public function test_no_recovery_query_runs_after_a_policy_failure(): void {
        $this->db->fail_session_statement = 'innodb_lock_wait_timeout';
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        $nonPolicy = array_filter( $this->db->statements, static fn( string $s ): bool => 1 !== preg_match( '/^SET SESSION/i', $s ) );
        $this->assertCount( 0, $nonPolicy, 'no read or write may follow a failed timeout policy' );
    }

    public function test_connection_is_closed_after_a_policy_failure(): void {
        $this->db->fail_session_statement = 'innodb_lock_wait_timeout';
        $this->repo()->record_unresolved_outcome( $this->outcome() );
        // v1.0.2: the factory owns the policy and releases the connection itself
        // when the policy cannot be applied, so the repository never receives it.
        $this->assertTrue( $this->db->closed, 'the connection must be released after a policy failure' );
    }

    // ══ Item 4 — blocking reads require schema verification ══════════════

    public function test_readable_myisam_table_blocks(): void {
        $this->db->engine = 'MyISAM';
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertFalse( (bool) $blocking['ok'] );
        $this->assertTrue( (bool) $blocking['blocking'] );
    }

    public function test_non_unique_identity_index_blocks(): void {
        $this->db->identity_index_non_unique = true;
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertFalse( (bool) $blocking['ok'] );
        $this->assertTrue( (bool) $blocking['blocking'] );
    }

    public function test_identity_index_on_the_wrong_column_blocks(): void {
        $this->db->identity_index_column = 'row_id';
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertFalse( (bool) $blocking['ok'] );
        $this->assertTrue( (bool) $blocking['blocking'] );
    }

    public function test_missing_table_blocks(): void {
        $this->db->table_missing = true;
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertFalse( (bool) $blocking['ok'] );
        $this->assertTrue( (bool) $blocking['blocking'] );
    }

    public function test_find_on_an_invalid_schema_is_not_reported_as_not_found(): void {
        $this->db->engine = 'MyISAM';
        $found = $this->repo()->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertFalse( (bool) $found['ok'] );
        $this->assertNotSame( 'not_found', (string) $found['status'] );
    }

    public function test_list_on_an_invalid_schema_is_not_reported_as_empty(): void {
        $this->db->identity_index_non_unique = true;
        $list = $this->repo()->list_unresolved_outcomes();
        $this->assertFalse( (bool) $list['ok'] );
        $this->assertSame( 'schema_failure', (string) $list['status'] );
    }

    public function test_healthy_schema_still_permits_a_genuine_no_marker_result(): void {
        $blocking = $this->repo()->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertTrue( (bool) $blocking['ok'] );
        $this->assertFalse( (bool) $blocking['blocking'] );
        $found = $this->repo()->find_unresolved_outcome( 'nothing:here' );
        $this->assertTrue( (bool) $found['ok'] );
        $this->assertSame( 'not_found', (string) $found['status'] );
    }

    // ══ Item 5 — reopening clears stale resolution state ═════════════════

    public function test_reopening_a_resolved_operation_clears_stale_resolution_metadata(): void {
        $repo = $this->repo();
        $first = $repo->record_unresolved_outcome( $this->outcome() );
        $repo->resolve_outcome( 'manual_approval:row:900', (int) $first['generation'], [
            'decision'          => 'acknowledged',
            'resolved_by'       => 42,
            'resolution_reason' => 'investigated',
            'evidence'          => [],
        ] );

        $reopened = $repo->record_unresolved_outcome( $this->outcome( [ 'reason' => 'happened again' ] ) );
        $this->assertTrue( (bool) $reopened['ok'] );

        $found = $repo->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertTrue( (bool) $found['found'] );
        $row = $found['row'];
        $this->assertSame( 'unresolved', (string) $row['state'] );
        $this->assertSame( 0, (int) $row['resolved_by'], 'stale resolver must be cleared' );
        $this->assertSame( '', (string) $row['resolution_reason'], 'stale resolution reason must be cleared' );
        $this->assertTrue( null === $row['resolved_at'] || '' === (string) $row['resolved_at'], 'stale resolved_at must be cleared' );
        $this->assertGreaterThan( (int) $first['generation'], (int) $reopened['generation'] );

        $blocking = $repo->has_blocking_outcome( [ 'row_id' => 900 ] );
        $this->assertTrue( (bool) $blocking['blocking'], 'a reopened outcome blocks again' );
    }

    // ══ Item 6 — immutable operation identity ════════════════════════════

    public function test_same_key_and_identity_increments_the_generation(): void {
        $repo = $this->repo();
        $a = $repo->record_unresolved_outcome( $this->outcome() );
        $b = $repo->record_unresolved_outcome( $this->outcome( [ 'reason' => 'again' ] ) );
        $this->assertTrue( (bool) $b['ok'] );
        $this->assertGreaterThan( (int) $a['generation'], (int) $b['generation'] );
    }

    public function test_same_key_with_a_different_row_is_refused(): void {
        $repo = $this->repo();
        $repo->record_unresolved_outcome( $this->outcome() );
        $clash = $repo->record_unresolved_outcome( $this->outcome( [ 'row_id' => 999 ] ) );
        $this->assertFalse( (bool) $clash['ok'] );
        $this->assertSame( 'identity_mismatch', (string) $clash['status'] );
        $found = $repo->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertSame( 900, (int) $found['row']['row_id'], 'the existing marker must be unchanged' );
    }

    public function test_same_key_with_a_different_batch_is_refused(): void {
        $repo = $this->repo();
        $repo->record_unresolved_outcome( $this->outcome() );
        $clash = $repo->record_unresolved_outcome( $this->outcome( [ 'batch_id' => 71 ] ) );
        $this->assertFalse( (bool) $clash['ok'] );
        $this->assertSame( 'identity_mismatch', (string) $clash['status'] );
        $found = $repo->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertSame( 70, (int) $found['row']['batch_id'] );
    }

    public function test_same_key_with_a_different_operation_type_is_refused(): void {
        $repo = $this->repo();
        $repo->record_unresolved_outcome( $this->outcome() );
        $clash = $repo->record_unresolved_outcome( $this->outcome( [ 'operation_type' => 'something_else' ] ) );
        $this->assertFalse( (bool) $clash['ok'] );
        $this->assertSame( 'identity_mismatch', (string) $clash['status'] );
        $found = $repo->find_unresolved_outcome( 'manual_approval:row:900' );
        $this->assertSame( 'manual_approval', (string) $found['row']['operation_type'] );
    }

    public function test_overlength_keys_are_rejected_rather_than_truncated(): void {
        $prefix = str_repeat( 'k', 185 );
        $a = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'operation_key' => $prefix . ':aaaaaaaaaaaaaaaaaaaa' ] ) );
        $b = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'operation_key' => $prefix . ':bbbbbbbbbbbbbbbbbbbb' ] ) );

        $this->assertFalse( (bool) $a['ok'], 'an overlength key must be rejected, not truncated' );
        $this->assertSame( 'invalid_operation_key', (string) $a['status'] );
        $this->assertFalse( (bool) $b['ok'] );
        $this->assertSame( 'invalid_operation_key', (string) $b['status'] );

        // Neither was silently truncated into the other's identity.
        $list = $this->repo()->list_unresolved_outcomes();
        $this->assertTrue( (bool) $list['ok'] );
        $this->assertCount( 0, $list['rows'], 'no truncated collision may exist' );
    }

    public function test_a_key_at_the_supported_limit_is_accepted(): void {
        $key = str_repeat( 'k', 191 );
        $result = $this->repo()->record_unresolved_outcome( $this->outcome( [ 'operation_key' => $key ] ) );
        $this->assertTrue( (bool) $result['ok'], (string) $result['status'] );
        $this->assertSame( $key, (string) $result['operation_key'] );
    }

    // ══ Item 7 — release identity is consistent ══════════════════════════

    public function test_source_release_version_matches_current_release(): void {
        $plugin = (string) file_get_contents( __DIR__ . '/../tmw-seo-engine.php' );
        $changelog = (string) file_get_contents( __DIR__ . '/../CHANGELOG.md' );
        $conn = (string) file_get_contents( __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php' );

        $release = '5.9.30-manual-approval-multi-owner-v1.0.0';
        $this->assertStringContainsString( 'Version: ' . $release, $plugin, 'the plugin header must state the PR-H release version' );
        $this->assertStringContainsString( "TMWSEO_ENGINE_VERSION', '" . $release, $plugin, 'the runtime constant must state the PR-H release version' );
        $this->assertStringContainsString( '## ' . $release, $changelog, 'the changelog must state the PR-H release version' );
    }
}
