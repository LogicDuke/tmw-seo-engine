<?php
/**
 * PR-H — isolation and security guards for the recovery subsystem.
 *
 * The whole value of PR-H rests on separation: the recovery table must be
 * untouchable by the normal approval transaction, and the recovery connection
 * must never be the global $wpdb. These guards pin that boundary.
 *
 * Behavioural coverage lives in UnresolvedTransactionOutcomeRepositoryTest;
 * these are structural invariants that cannot be asserted at runtime.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecoveryIsolationGuardTest extends TestCase {

    private const TABLE = 'tmw_unresolved_transaction_outcomes';

    private static string $repo = '';
    private static string $conn = '';

    protected function setUp(): void {
        if ( '' === self::$repo ) {
            self::$repo = (string) file_get_contents( __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php' );
            self::$conn = (string) file_get_contents( __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php' );
        }
    }

    /** @return array<int,string> every production PHP file outside includes/recovery */
    private function production_files_outside_recovery(): array {
        $root = realpath( __DIR__ . '/../includes' );
        $out  = [];
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            $path = (string) $file->getPathname();
            if ( '.php' !== substr( $path, -4 ) ) { continue; }
            if ( false !== strpos( $path, DIRECTORY_SEPARATOR . 'recovery' . DIRECTORY_SEPARATOR ) ) { continue; }
            $out[] = $path;
        }
        return $out;
    }

    // ── Table isolation ───────────────────────────────────────────────────

    public function test_recovery_table_is_referenced_only_by_the_recovery_subsystem_and_its_schema(): void {
        $offenders = [];
        foreach ( $this->production_files_outside_recovery() as $path ) {
            if ( false === strpos( (string) file_get_contents( $path ), self::TABLE ) ) { continue; }
            // The schema class legitimately owns the DDL and the CLI legitimately
            // drives the operator workflow; nothing else may name the table.
            $base = basename( $path );
            if ( 'class-schema.php' === $base || 'class-cli.php' === $base ) { continue; }
            $offenders[] = $base;
        }
        $this->assertSame( [], $offenders, 'recovery table referenced outside the recovery subsystem: ' . implode( ', ', $offenders ) );
    }

    public function test_candidate_assignment_import_and_approval_code_never_touch_the_recovery_table(): void {
        foreach ( [
            'keywords/class-keyword-pool-candidate-repository.php',
            'keywords/class-keyword-assignment-repository.php',
            'keywords/class-keyword-pool-import-batch-repository.php',
            'keywords/class-keyword-pool-selected-import-service.php',
            'admin/class-keyword-pools-admin-page.php',
        ] as $rel ) {
            $path = __DIR__ . '/../includes/' . $rel;
            if ( ! file_exists( $path ) ) { continue; }
            $this->assertStringNotContainsString( self::TABLE, (string) file_get_contents( $path ), $rel . ' must not reference the recovery table' );
        }
    }

    // ── Connection isolation ──────────────────────────────────────────────

    public function test_independent_connection_construction_is_confined_to_the_recovery_subsystem(): void {
        $offenders = [];
        foreach ( $this->production_files_outside_recovery() as $path ) {
            if ( false !== strpos( (string) file_get_contents( $path ), 'new \\wpdb(' ) ) { $offenders[] = basename( $path ); }
        }
        $this->assertSame( [], $offenders, 'independent connections must exist only in the recovery subsystem' );
        $this->assertStringContainsString( 'extends \\wpdb', self::$conn, 'recovery must use its confined wpdb subclass' );
        $this->assertStringContainsString( 'db_connect( false )', self::$conn, 'initial recovery connection must not bail' );
        $this->assertStringContainsString( 'parent::check_connection( false )', self::$conn, 'reconnects must not bail' );
        $this->assertStringContainsString( '$this->reconnect_retries = 1', self::$conn, 'recovery reconnect retries must remain bounded' );
        $this->assertStringNotContainsString( 'new \\wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST )', self::$conn, 'core wpdb constructor would connect with allow_bail=true' );
    }

    public function test_recovery_repository_never_writes_through_the_global_wpdb(): void {
        // The repository must not reach for the global connection at all.
        $this->assertStringNotContainsString( 'global $wpdb', self::$repo );
        $this->assertStringNotContainsString( "\$GLOBALS['wpdb']", self::$repo );
        // Every statement goes through the injected/opened connection.
        $this->assertStringContainsString( '$this->open_connection()', self::$repo );
    }

    public function test_connection_is_closed_after_every_operation(): void {
        // Each public entry point that opens a connection closes it in finally.
        $opens  = substr_count( self::$repo, '$this->open_connection()' );
        $closes = substr_count( self::$repo, '$this->connections->close( $db );' );
        $this->assertGreaterThan( 0, $opens );
        $this->assertSame( $opens, $closes, 'every opened recovery connection must be released' );
        $this->assertSame( $closes, substr_count( self::$repo, '} finally {' ), 'releases must be in finally blocks' );
    }

    public function test_recovery_connection_never_issues_transaction_verbs(): void {
        foreach ( [ 'START TRANSACTION', "'COMMIT'", "'ROLLBACK'" ] as $verb ) {
            $this->assertStringNotContainsString( $verb, self::$repo, 'recovery must never join or open a transaction: ' . $verb );
            $this->assertStringNotContainsString( $verb, self::$conn, 'recovery must never join or open a transaction: ' . $verb );
        }
    }

    public function test_short_lock_wait_timeout_is_applied(): void {
        $this->assertStringContainsString( 'innodb_lock_wait_timeout = 3', self::$conn );
        $this->assertStringContainsString( 'SET SESSION lock_wait_timeout = 3', self::$conn );
        // v1.0.2: ONE contract — the factory applies and verifies the policy and
        // returns only policy-ready connections; the repository must NOT
        // re-apply it, or every production operation would issue it twice.
        $this->assertStringNotContainsString(
            'UnresolvedTransactionOutcomeConnection::apply_session_policy(', self::$repo,
            'the repository must not re-apply the policy'
        );
        $this->assertStringContainsString( 'connection_policy_failure', self::$repo, 'but it must preserve the status' );
    }

    // ── No forbidden fallbacks ────────────────────────────────────────────

    public function test_no_filesystem_fallback_exists(): void {
        foreach ( [ 'file_put_contents', 'fopen(', 'WP_CONTENT_DIR', 'wp_filesystem' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, self::$repo, 'no filesystem fallback is permitted' );
            $this->assertStringNotContainsString( $needle, self::$conn, 'no filesystem fallback is permitted' );
        }
    }

    public function test_no_serialized_whole_option_store_exists(): void {
        // The only option used is the operator-visible schema error and the
        // schema version — never a serialized store of markers.
        foreach ( [ self::$repo, self::$conn ] as $source ) {
            foreach ( [ 'get_option(', 'update_option(', 'delete_option(', 'add_option(' ] as $call ) {
                $this->assertStringNotContainsString( $call, $source, 'the recovery runtime must not use the options API' );
            }
        }
    }

    // ── Credential safety ─────────────────────────────────────────────────

    public function test_credentials_are_never_logged_or_returned(): void {
        foreach ( [ self::$repo, self::$conn ] as $source ) {
            // No error_log / return path may interpolate a credential constant.
            preg_match_all( '/error_log\((.*?)\);/s', $source, $m );
            foreach ( $m[1] as $call ) {
                foreach ( [ 'DB_PASSWORD', 'DB_USER', 'DB_HOST', 'DB_NAME' ] as $constant ) {
                    $this->assertStringNotContainsString( $constant, $call, 'credentials must never be logged' );
                }
            }
        }
        // DB_PASSWORD is used only where the connection is constructed — never
        // in a string, a return value or a log line.
        $this->assertStringNotContainsString( '"' . 'DB_PASSWORD', self::$conn );
        $this->assertSame( 1, substr_count( self::$conn, 'DB_PASSWORD,' ), 'DB_PASSWORD is passed once, to the wpdb constructor' );
        $this->assertStringNotContainsString( 'DB_PASSWORD', self::$repo, 'the repository never sees credentials' );
    }

    public function test_connection_failures_do_not_propagate_driver_messages(): void {
        // wpdb/mysqli messages can contain host and user details, so the factory
        // returns a fixed string instead of the underlying error.
        $this->assertStringContainsString( "'recovery connection could not be established'", self::$conn );
        // The driver's own message is never surfaced: the caught exception is
        // not interpolated into any returned value or log line.
        $this->assertStringNotContainsString( '$e->getMessage()', self::$conn );
        $this->assertStringNotContainsString( '$db->error', self::$conn === '' ? 'x' : str_replace( 'empty( $db->error )', '', self::$conn ) );
    }

    // ── Scope: manual approval untouched ──────────────────────────────────

    public function test_pr_h_does_not_reference_manual_approval_behaviour(): void {
        foreach ( [ 'approve_import_row', 'manual_approval', 'KeywordPoolManualApproval', 'assignment_key' ] as $needle ) {
            if ( 'manual_approval' === $needle || 'assignment_key' === $needle ) {
                // These may appear only as opaque data values, never as calls.
                $this->assertStringNotContainsString( $needle . '(', self::$repo );
                continue;
            }
            $this->assertStringNotContainsString( $needle, self::$repo, 'PR-H must not couple to manual approval: ' . $needle );
        }
    }
}
