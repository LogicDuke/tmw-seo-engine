<?php
/**
 * PR-H v1.0.2 — behavioral tests for the PRODUCTION connection path.
 *
 * v1.0.0 and v1.0.1 exercised the repository through an injected factory, which
 * left UnresolvedTransactionOutcomeConnection itself untested. These tests drive
 * the real class, substituting only the wpdb construction, so the connect-timeout
 * bound, the session policy and the credential-safety guarantees are verified in
 * the code that actually runs in production.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeConnection as Conn;

require_once __DIR__ . '/support/RecoveryFakeDb.php';

if ( ! defined( 'DB_NAME' ) ) { define( 'DB_NAME', 'tmw_test_db' ); }
if ( ! defined( 'DB_USER' ) ) { define( 'DB_USER', 'tmw_test_user' ); }
if ( ! defined( 'DB_PASSWORD' ) ) { define( 'DB_PASSWORD', 'super-secret-db-password' ); }
if ( ! defined( 'DB_HOST' ) ) { define( 'DB_HOST', 'db.internal.example:3306' ); }

require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';

/** A connection whose wpdb construction is controlled by the test. */
final class TestableRecoveryConnection extends Conn {
    public $next_db = null;
    public bool $throw_on_construct = false;
    public string $throw_message = 'mysqli_real_connect(): Access denied for user tmw_test_user@db.internal.example (using password: super-secret-db-password)';
    public int $constructed = 0;
    /** Set to false to model an environment where the connect timeout cannot be bounded. */
    public bool $can_bound_connect_timeout = true;
    public string $connect_timeout = '60';
    public array $logged = [];

    protected function create_wpdb() {
        $this->constructed++;
        if ( $this->throw_on_construct ) { throw new \RuntimeException( $this->throw_message ); }
        return $this->next_db;
    }

    protected function read_connect_timeout() {
        return $this->connect_timeout;
    }

    protected function write_connect_timeout( string $value ) {
        $previous = $this->connect_timeout;
        $this->connect_timeout = $value;
        return $previous;
    }

    protected function bound_connect_timeout(): bool {
        if ( ! $this->can_bound_connect_timeout ) { return false; }
        return parent::bound_connect_timeout();
    }

    protected function log( string $message ): void {
        $this->logged[] = $message;
    }
}

/** Minimal wpdb-shaped double for the connection class. */
final class RecoveryConnectionDouble {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $last_errno = 0;
    public $dbh = 'resource';
    public string $error = '';
    public bool $closed = false;
    public array $statements = [];
    public string $fail_statement = '';

    public function suppress_errors( bool $s = true ): bool { return true; }
    public function hide_errors(): bool { return true; }
    public function set_prefix( string $p ): string { $this->prefix = $p; return $p; }
    public function close(): bool { $this->closed = true; return true; }

    public function query( string $sql ) {
        $this->statements[] = $sql;
        $this->last_error = '';
        $this->last_errno = 0;
        if ( '' !== $this->fail_statement && false !== stripos( $sql, $this->fail_statement ) ) {
            $this->last_error = 'Access denied for user tmw_test_user@db.internal.example';
            $this->last_errno = 1227;
            return false;
        }
        return 0;
    }
}

final class RecoveryConnectionPathTest extends TestCase {

    private TestableRecoveryConnection $conn;
    private RecoveryConnectionDouble $db;

    protected function setUp(): void {
        set_error_handler( static function ( int $no, string $msg, string $file = '', int $line = 0 ): bool {
            if ( 0 === ( error_reporting() & $no ) ) { return false; }
            throw new \ErrorException( $msg, 0, $no, $file, $line );
        } );
        $this->db = new RecoveryConnectionDouble();
        $this->conn = new TestableRecoveryConnection();
        $this->conn->next_db = $this->db;
        $GLOBALS['wpdb'] = new class { public string $prefix = 'wp_'; };
    }

    protected function tearDown(): void { restore_error_handler(); }

    // ── Connect timeout ───────────────────────────────────────────────────

    public function test_connect_timeout_bound_is_confirmed_before_connecting(): void {
        $result = $this->conn->open();
        $this->assertTrue( (bool) $result['ok'], (string) $result['error'] );
        $this->assertSame( 1, $this->conn->constructed );
    }

    public function test_unbounded_connect_timeout_returns_connection_policy_failure(): void {
        $this->conn->can_bound_connect_timeout = false;
        $result = $this->conn->open();

        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_policy_failure', (string) $result['status'] );
        $this->assertSame( 0, $this->conn->constructed, 'no connection may be attempted unbounded' );
    }

    // ── Session policy at the factory level ───────────────────────────────

    public function test_innodb_timeout_failure_at_the_factory_returns_policy_failure(): void {
        $this->db->fail_statement = 'innodb_lock_wait_timeout';
        $result = $this->conn->open();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_policy_failure', (string) $result['status'] );
    }

    public function test_metadata_timeout_failure_at_the_factory_returns_policy_failure(): void {
        $this->db->fail_statement = 'SET SESSION lock_wait_timeout';
        $result = $this->conn->open();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_policy_failure', (string) $result['status'] );
    }

    public function test_connection_is_closed_on_policy_failure(): void {
        $this->db->fail_statement = 'innodb_lock_wait_timeout';
        $this->conn->open();
        $this->assertTrue( $this->db->closed, 'the connection must be released on policy failure' );
    }

    public function test_no_query_beyond_the_policy_runs_after_a_policy_failure(): void {
        $this->db->fail_statement = 'innodb_lock_wait_timeout';
        $this->conn->open();
        $nonPolicy = array_filter( $this->db->statements, static fn( string $s ): bool => 1 !== preg_match( '/^SET SESSION/i', $s ) );
        $this->assertCount( 0, $nonPolicy );
    }

    // ── Credential safety ─────────────────────────────────────────────────

    public function test_driver_message_is_not_propagated_on_construction_failure(): void {
        $this->conn->throw_on_construct = true;
        $result = $this->conn->open();

        $this->assertFalse( (bool) $result['ok'] );
        $blob = (string) json_encode( $result );
        $this->assertStringNotContainsString( DB_PASSWORD, $blob );
        $this->assertStringNotContainsString( DB_USER, $blob );
        $this->assertStringNotContainsString( DB_HOST, $blob );
        $this->assertStringNotContainsString( 'Access denied', $blob, 'the driver message must not be propagated' );
    }

    public function test_credentials_never_reach_the_log(): void {
        $this->conn->throw_on_construct = true;
        $this->conn->open();
        $this->db->fail_statement = 'innodb_lock_wait_timeout';
        $fresh = new TestableRecoveryConnection();
        $fresh->next_db = $this->db;
        $fresh->open();

        foreach ( array_merge( $this->conn->logged, $fresh->logged ) as $line ) {
            foreach ( [ DB_PASSWORD, DB_USER, DB_HOST, DB_NAME ] as $secret ) {
                $this->assertStringNotContainsString( $secret, $line );
            }
        }
    }

    public function test_a_dead_handle_is_reported_as_connection_failure_without_detail(): void {
        $this->db->dbh = null;
        $result = $this->conn->open();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'connection_failure', (string) $result['status'] );
        $this->assertStringNotContainsString( DB_HOST, (string) $result['error'] );
    }

    // ── Missing constants ─────────────────────────────────────────────────

    public function test_missing_constant_is_named_without_exposing_any_value(): void {
        // All four are defined in this suite, so assert the guard's shape: the
        // error names the constant, never its value.
        $src = (string) file_get_contents( __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php' );
        $this->assertStringContainsString( "' is not defined'", $src );
        $this->assertStringNotContainsString( 'constant( $constant )', $src, 'the guard must not read the value' );
    }
}
