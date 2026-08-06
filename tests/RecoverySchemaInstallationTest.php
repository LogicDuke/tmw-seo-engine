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
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';
require_once __DIR__ . '/../includes/db/class-schema.php';

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
        $GLOBALS['_tmw_dbdelta_queries'][] = (string) $queries;
        if ( ! empty( $GLOBALS['_tmw_dbdelta_should_fail'] ) ) { return []; }
        if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof RecoveryFakeDb
            && false !== stripos( (string) $queries, 'resolution_decision' ) ) {
            $GLOBALS['wpdb']->missing_columns = array_values( array_diff(
                $GLOBALS['wpdb']->missing_columns,
                [ 'resolution_decision' ]
            ) );
        }
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
        $GLOBALS['_tmw_dbdelta_queries'] = [];
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

    /** @return array<string,mixed> */
    private function outcome( string $key ): array {
        return [
            'operation_key'            => $key,
            'operation_type'           => 'manual_approval',
            'row_id'                    => 900,
            'batch_id'                  => 70,
            'expected_candidate_id'     => 10,
            'expected_assignment_key'   => 'assignment-a',
            'correlation_id'            => 'corr-a',
            'reason'                    => 'commit_unknown',
            'evidence'                  => [ 'state' => 'open' ],
        ];
    }

    // ── Successful installation ───────────────────────────────────────────

    public function test_verified_installation_stamps_the_version_and_clears_the_error(): void {
        update_option( Repo::SCHEMA_ERROR_OPTION, 'stale error from a previous attempt', false );

        $result = Schema::ensure_unresolved_transaction_outcome_schema();

        $this->assertTrue( (bool) $result['ok'], (string) ( $result['reason'] ?? '' ) );
        $this->assertSame( Schema::RECOVERY_SCHEMA_VERSION, $this->version() );
        $this->assertSame( '', $this->error(), 'a verified install clears the operator error' );
    }

    public function test_installation_ddl_includes_resolution_decision(): void {
        Schema::ensure_unresolved_transaction_outcome_schema();

        $this->assertNotEmpty( $GLOBALS['_tmw_dbdelta_queries'] );
        $this->assertStringContainsString(
            "resolution_decision VARCHAR(20) NOT NULL DEFAULT ''",
            implode( "\n", $GLOBALS['_tmw_dbdelta_queries'] )
        );
    }

    public function test_v103_schema_is_upgraded_with_resolution_decision(): void {
        update_option( Schema::RECOVERY_SCHEMA_VERSION_OPTION, '1.0.0', false );
        $this->primary->missing_columns = [ 'resolution_decision' ];

        Schema::upgrade_unresolved_transaction_outcome_schema();

        $this->assertSame( [], $this->primary->missing_columns );
        $this->assertSame( Schema::RECOVERY_SCHEMA_VERSION, $this->version() );
        $this->assertSame( 1, $GLOBALS['_tmw_dbdelta_calls'] );
    }

    public function test_schema_verification_fails_closed_without_resolution_decision(): void {
        $this->primary->missing_columns = [ 'resolution_decision' ];

        $result = Schema::verify_unresolved_transaction_outcome_schema();

        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'resolution_decision', $result['reason'] );
    }

    public function test_installation_ddl_uses_case_sensitive_operation_key(): void {
        Schema::ensure_unresolved_transaction_outcome_schema();

        $this->assertStringContainsString(
            'operation_key VARBINARY(191) NOT NULL',
            implode( "\n", $GLOBALS['_tmw_dbdelta_queries'] )
        );
    }

    public function test_v104_schema_is_upgraded_to_case_sensitive_operation_key(): void {
        update_option( Schema::RECOVERY_SCHEMA_VERSION_OPTION, '1.0.1', false );
        $this->primary->operation_key_type = 'varchar(191)';
        $this->primary->operation_key_collation = 'utf8mb4_unicode_ci';

        Schema::upgrade_unresolved_transaction_outcome_schema();

        $this->assertSame( 'varbinary(191)', $this->primary->operation_key_type );
        $this->assertNull( $this->primary->operation_key_collation );
        $this->assertSame( Schema::RECOVERY_SCHEMA_VERSION, $this->version() );
        $this->assertSame( 1, $GLOBALS['_tmw_dbdelta_calls'] );

        $altered = false;
        foreach ( $this->primary->statements as $statement ) {
            if ( false !== stripos(
                $statement,
                'MODIFY operation_key VARBINARY(191) NOT NULL'
            ) ) {
                $altered = true;
            }
        }
        $this->assertTrue( $altered, 'the existing v1.0.4 column must be explicitly migrated' );
    }

    public function test_primary_verification_rejects_case_insensitive_operation_key(): void {
        $this->primary->operation_key_type = 'varchar(191)';
        $this->primary->operation_key_collation = 'utf8mb4_unicode_ci';

        $result = Schema::verify_unresolved_transaction_outcome_schema();

        $this->assertFalse( (bool) $result['ok'] );
        $this->assertStringContainsString( 'VARBINARY(191)', (string) $result['reason'] );
    }

    public function test_runtime_verification_rejects_case_insensitive_operation_key(): void {
        $this->primary->operation_key_type = 'varchar(191)';
        $this->primary->operation_key_collation = 'utf8mb4_unicode_ci';

        $repo = new Repo( new RecoveryFakeConnectionFactory( $this->primary ) );
        $result = $repo->verify_schema();

        $this->assertFalse( (bool) $result['ok'] );
        $this->assertSame( 'schema_failure', (string) $result['status'] );
        $this->assertStringContainsString( 'VARBINARY(191)', (string) $result['reason'] );
    }

    public function test_operation_keys_that_differ_only_by_case_remain_distinct(): void {
        $repo = new Repo( new RecoveryFakeConnectionFactory( $this->primary ) );
        $upper = 'Manual_Approval:row:900';
        $lower = 'manual_approval:row:900';

        $first = $repo->record_unresolved_outcome( $this->outcome( $upper ) );
        $second = $repo->record_unresolved_outcome( $this->outcome( $lower ) );

        $this->assertTrue( (bool) $first['ok'], (string) $first['reason'] );
        $this->assertTrue( (bool) $second['ok'], (string) $second['reason'] );

        $found_upper = $repo->find_unresolved_outcome( $upper );
        $found_lower = $repo->find_unresolved_outcome( $lower );

        $this->assertTrue( (bool) $found_upper['found'] );
        $this->assertTrue( (bool) $found_lower['found'] );
        $this->assertSame( $upper, (string) $found_upper['row']['operation_key'] );
        $this->assertSame( $lower, (string) $found_lower['row']['operation_key'] );
        $this->assertNotSame(
            (int) $found_upper['row']['id'],
            (int) $found_lower['row']['id']
        );
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
