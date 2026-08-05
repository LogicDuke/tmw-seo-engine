<?php
/**
 * PR-H v1.0.1 — verified, retryable recovery schema installation (item 3).
 *
 * These exercise the INSTALL path, which legitimately runs on the primary
 * connection during initialization, so the primary $wpdb here is a working
 * double rather than a throwing one.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Schema;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

require_once __DIR__ . '/support/RecoveryFakeDb.php';
require_once __DIR__ . '/../includes/db/class-schema.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';

if ( ! function_exists( 'dbDelta' ) ) {
    /**
     * Test stub for the schema-installation path. Records invocations so retry
     * behaviour can be asserted, and can be forced to do nothing via
     * $_tmw_dbdelta_should_fail. The shared bootstrap is deliberately not
     * modified for this.
     *
     * @return array<int,string>
     */
    function dbDelta( $queries = '', $execute = true ) {
        $GLOBALS['_tmw_dbdelta_calls'] = (int) ( $GLOBALS['_tmw_dbdelta_calls'] ?? 0 ) + 1;
        if ( ! empty( $GLOBALS['_tmw_dbdelta_should_fail'] ) ) { return []; }
        return [ 'created' ];
    }
}

final class RecoverySchemaInstallationTest extends TestCase {

    private RecoveryFakeDb $primary;

    protected function setUp(): void {
        set_error_handler( static function ( int $no, string $msg, string $file = '', int $line = 0 ): bool {
            if ( 0 === ( error_reporting() & $no ) ) { return false; }
            throw new \ErrorException( $msg, 0, $no, $file, $line );
        } );

        $this->primary = new RecoveryFakeDb( RecoveryStore::fresh( 'install' ) );
        $GLOBALS['wpdb'] = $this->primary;
        $GLOBALS['_tmw_test_options'] = [];
        // dbDelta is a no-op by default: the table double already "exists".
        $GLOBALS['_tmw_dbdelta_calls'] = 0;
        $GLOBALS['_tmw_dbdelta_should_fail'] = false;
    }

    protected function tearDown(): void {
        restore_error_handler();
        @unlink( $this->primary->store()->path );
    }

    private function version(): string {
        return (string) get_option( Schema::RECOVERY_SCHEMA_VERSION_OPTION, '' );
    }

    private function error(): string {
        return (string) get_option( Repo::SCHEMA_ERROR_OPTION, '' );
    }

    // ── Successful installation ───────────────────────────────────────────

    public function test_verified_installation_stamps_the_version_and_clears_the_error(): void {
        update_option( Repo::SCHEMA_ERROR_OPTION, 'stale error from a previous attempt', false );

        $result = Schema::ensure_unresolved_transaction_outcome_schema();

        $this->assertTrue( (bool) $result['ok'], (string) ( $result['reason'] ?? '' ) );
        $this->assertSame( Schema::RECOVERY_SCHEMA_VERSION, $this->version() );
        $this->assertSame( '', $this->error(), 'a verified install clears the operator error' );
    }

    // ── Failures must not stamp the version ───────────────────────────────

    public function test_dbdelta_failure_does_not_stamp_the_version(): void {
        $GLOBALS['_tmw_dbdelta_should_fail'] = true;
        $this->primary->table_missing = true;

        $result = Schema::ensure_unresolved_transaction_outcome_schema();

        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( '', $this->version(), 'a failed install must not stamp the schema version' );
        $this->assertNotSame( '', $this->error(), 'a failed install must persist an operator-visible error' );
    }

    public function test_table_absent_after_dbdelta_does_not_stamp_the_version(): void {
        $this->primary->table_missing = true;
        $result = Schema::ensure_unresolved_transaction_outcome_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( '', $this->version() );
        $this->assertStringContainsString( 'table', strtolower( $this->error() ) );
    }

    public function test_wrong_engine_does_not_stamp_the_version(): void {
        $this->primary->engine = 'MyISAM';
        $result = Schema::ensure_unresolved_transaction_outcome_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( '', $this->version() );
        $this->assertStringContainsString( 'engine', strtolower( $this->error() ) );
    }

    public function test_missing_column_does_not_stamp_the_version(): void {
        $this->primary->missing_columns = [ 'generation' ];
        $result = Schema::ensure_unresolved_transaction_outcome_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( '', $this->version() );
        $this->assertStringContainsString( 'generation', $this->error() );
    }

    public function test_non_unique_operation_identity_does_not_stamp_the_version(): void {
        $this->primary->identity_index_non_unique = true;
        $result = Schema::ensure_unresolved_transaction_outcome_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( '', $this->version() );
        $this->assertStringContainsString( 'unique', strtolower( $this->error() ) );
    }

    public function test_identity_index_covering_the_wrong_column_does_not_stamp_the_version(): void {
        $this->primary->identity_index_column = 'row_id';
        $result = Schema::ensure_unresolved_transaction_outcome_schema();
        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( '', $this->version() );
        $this->assertStringContainsString( 'operation_key', $this->error() );
    }

    // ── Retry semantics ───────────────────────────────────────────────────

    public function test_the_next_initialization_retries_after_a_failed_install(): void {
        $this->primary->engine = 'MyISAM';
        Schema::ensure_unresolved_transaction_outcome_schema();
        $this->assertSame( '', $this->version() );

        // Operator fixes the engine; the next normal initialization must retry.
        $this->primary->engine = 'InnoDB';
        Schema::upgrade_unresolved_transaction_outcome_schema();

        $this->assertSame( Schema::RECOVERY_SCHEMA_VERSION, $this->version() );
        $this->assertSame( '', $this->error() );
    }

    public function test_upgrade_does_not_skip_when_the_version_matches_but_the_schema_is_invalid(): void {
        // Version stamped, but the table has since been altered or lost.
        update_option( Schema::RECOVERY_SCHEMA_VERSION_OPTION, Schema::RECOVERY_SCHEMA_VERSION, false );
        $this->primary->missing_columns = [ 'generation' ];

        Schema::upgrade_unresolved_transaction_outcome_schema();

        $this->assertNotSame( '', $this->error(), 'an invalid schema must be detected even when the version matches' );
        $this->assertSame( '', $this->version(), 'the stale version stamp must be cleared' );
    }

    public function test_upgrade_is_a_no_op_when_version_matches_and_schema_is_valid(): void {
        Schema::ensure_unresolved_transaction_outcome_schema();
        $before = $GLOBALS['_tmw_dbdelta_calls'];
        Schema::upgrade_unresolved_transaction_outcome_schema();
        $this->assertSame( $before, $GLOBALS['_tmw_dbdelta_calls'], 'a healthy verified schema needs no further dbDelta' );
        $this->assertSame( Schema::RECOVERY_SCHEMA_VERSION, $this->version() );
    }
}
