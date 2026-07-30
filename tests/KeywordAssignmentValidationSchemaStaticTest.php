<?php
/**
 * PR-F — validation-fixture schema and production-safety tests (rev 2).
 *
 * Schema: DDL validity for the fixtures table (v2, incl. the UNIQUE
 * active-identity concurrency indexes) and the new APPEND-ONLY fixture
 * audit table, shared single DDL sources between installer and runtime
 * guard, behavioral idempotency across BOTH tables, InnoDB conversion, no
 * destructive statements, no backfill, no row writes.
 *
 * Safety: proves the validation layer executes nothing on plugin load and
 * that no production path references the validation classes. Proves the
 * OPT-IN override contract at the source level: the migration service holds
 * NO stored/static/global override state and no fixture-repository access —
 * overrides exist only as an explicit per-call analyze() argument that the
 * executor forwards verbatim, and only the explicit CLI workflow supplies
 * them. Proves the CLI command is dry-run by default with explicit actions
 * only (incl. run-stale-validation and recover-manual-review), rejects
 * non-secondary manual roles at the CLI layer, and that all validation
 * logging is gated behind WP_DEBUG / TMWSEO_KW_VALIDATION_DEBUG. Proves
 * fixture rows are never deleted and audit rows have no update or delete
 * path anywhere.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KeywordAssignmentValidationSchemaStaticTest extends TestCase {

    private static string $schema_source = '';
    private static string $fixtures_ddl = '';
    private static string $audit_ddl = '';
    private static string $guard_source = '';
    private static string $migration_source = '';
    private static string $execution_source = '';
    private static string $repository_source = '';
    private static string $service_source = '';
    private static string $cli_source = '';
    private static int $prefix_counter = 0;

    private $original_wpdb;

    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../includes/db/class-schema.php';
        self::$schema_source = (string) file_get_contents( __DIR__ . '/../includes/db/class-schema.php' );
        $start = strpos( self::$schema_source, 'public static function get_keyword_assignment_validation_fixtures_schema_sql' );
        $end   = strpos( self::$schema_source, 'private static function safe_sql_hash' );
        self::$guard_source = ( false !== $start && false !== $end ) ? substr( self::$schema_source, $start, $end - $start ) : '';
        self::$fixtures_ddl = \TMWSEO\Engine\Schema::get_keyword_assignment_validation_fixtures_schema_sql( 'wp_tmw_keyword_assignment_validation_fixtures', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
        self::$audit_ddl = \TMWSEO\Engine\Schema::get_keyword_assignment_validation_fixture_audit_schema_sql( 'wp_tmw_keyword_assignment_validation_fixture_audit', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
        self::$migration_source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-migration-service.php' );
        self::$execution_source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-review-execution-service.php' );
        self::$repository_source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-validation-fixture-repository.php' );
        self::$service_source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-validation-service.php' );
        self::$cli_source = (string) file_get_contents( __DIR__ . '/../includes/cli/class-cli.php' );
    }

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['_tmw_test_options'] = [];
        $GLOBALS['_tmw_validation_dbdelta_calls'] = 0;
        $this->assertNotSame( '', self::$guard_source, 'Guard source extraction failed.' );
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    /** Fresh fake wpdb with a unique prefix (defeats the table-exists cache). */
    private function freshWpdb( bool $exists = false, string $engine = 'InnoDB' ): ValidationSchemaGuardWpdb {
        $wpdb = new ValidationSchemaGuardWpdb();
        $wpdb->prefix = 'wpv' . ( ++self::$prefix_counter ) . '_';
        $wpdb->validation_table_exists = $exists;
        $wpdb->audit_table_exists = $exists;
        $wpdb->validation_engine = $engine;
        $wpdb->audit_engine = $engine;
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    // ── DDL validity ──────────────────────────────────────────────────────

    public function test_fixtures_table_sql_is_structurally_valid(): void {
        $ddl = self::$fixtures_ddl;
        $this->assertStringStartsWith( 'CREATE TABLE wp_tmw_keyword_assignment_validation_fixtures (', $ddl );
        $this->assertSame( substr_count( $ddl, '(' ), substr_count( $ddl, ')' ), 'Balanced parentheses.' );
        $this->assertStringContainsString( 'PRIMARY KEY (id)', $ddl );
        $this->assertStringContainsString( ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4', $ddl );
        $this->assertStringNotContainsString( 'FOREIGN KEY', $ddl, 'No FKs, matching every existing keyword table.' );
        $this->assertStringNotContainsString( 'GENERATED', $ddl );
        $this->assertStringNotContainsString( ' AS (', $ddl );
        foreach ( [
            'validation_token VARCHAR(64) NOT NULL',
            'fixture_type VARCHAR(30) NOT NULL',
            'keyword_candidate_id BIGINT(20) UNSIGNED NOT NULL',
            'review_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
            'assignment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
            'original_values LONGTEXT NULL',
            'override_values LONGTEXT NULL',
            "state VARCHAR(20) NOT NULL DEFAULT 'active'",
            'active_token_key VARCHAR(64) NULL',
            'active_scope_key VARCHAR(191) NULL',
            'created_by VARCHAR(191) NOT NULL',
            'created_at DATETIME NOT NULL',
            'restored_at DATETIME NULL',
        ] as $fragment ) {
            $this->assertStringContainsString( $fragment, $ddl, 'Missing DDL fragment: ' . $fragment );
        }
        foreach ( [
            'UNIQUE KEY active_token_key (active_token_key)',
            'UNIQUE KEY active_scope_key (active_scope_key)',
            'KEY validation_token (validation_token)',
            'KEY fixture_state (fixture_type, state)',
            'KEY candidate (keyword_candidate_id)',
            'KEY review_id (review_id)',
        ] as $index ) {
            $this->assertStringContainsString( $index, $ddl, 'Missing index: ' . $index );
        }
    }

    public function test_audit_table_sql_is_structurally_valid_and_append_only(): void {
        $ddl = self::$audit_ddl;
        $this->assertStringStartsWith( 'CREATE TABLE wp_tmw_keyword_assignment_validation_fixture_audit (', $ddl );
        $this->assertSame( substr_count( $ddl, '(' ), substr_count( $ddl, ')' ), 'Balanced parentheses.' );
        $this->assertStringContainsString( 'PRIMARY KEY (id)', $ddl );
        $this->assertStringContainsString( ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4', $ddl );
        $this->assertStringNotContainsString( 'FOREIGN KEY', $ddl );
        foreach ( [
            'fixture_id BIGINT(20) UNSIGNED NOT NULL',
            'validation_token VARCHAR(64) NOT NULL',
            'fixture_type VARCHAR(30) NOT NULL',
            'action VARCHAR(40) NOT NULL',
            "old_state VARCHAR(20) NOT NULL DEFAULT ''",
            "new_state VARCHAR(20) NOT NULL DEFAULT ''",
            "actor VARCHAR(191) NOT NULL DEFAULT ''",
            "note VARCHAR(500) NOT NULL DEFAULT ''",
            "command_source VARCHAR(100) NOT NULL DEFAULT ''",
            "payload_hash VARCHAR(40) NOT NULL DEFAULT ''",
            'created_at DATETIME NOT NULL',
        ] as $fragment ) {
            $this->assertStringContainsString( $fragment, $ddl, 'Missing audit DDL fragment: ' . $fragment );
        }
        foreach ( [ 'KEY fixture_id (fixture_id)', 'KEY validation_token (validation_token)', 'KEY fixture_action (action)' ] as $index ) {
            $this->assertStringContainsString( $index, $ddl, 'Missing audit index: ' . $index );
        }
        // No update or delete path exists for audit rows anywhere.
        $this->assertStringNotContainsString( 'update( $this->audit_table', self::$repository_source );
        $this->assertStringNotContainsString( 'delete( $this->audit_table', self::$repository_source );
        $this->assertStringNotContainsString( 'DELETE FROM ' . "'" , self::$repository_source );
        $this->assertSame( 1, substr_count( self::$repository_source, 'wpdb->insert( $this->audit_table()' ), 'Exactly one append-only audit insert site.' );
    }

    // ── Installer/guard wiring and idempotency ────────────────────────────

    public function test_installer_and_runtime_guard_share_one_ddl_source(): void {
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_validation_fixtures_schema_sql($keyword_assignment_validation_fixtures, $charset_collate));', self::$schema_source, 'Activation installs the fixtures table from the shared DDL.' );
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_validation_fixture_audit_schema_sql($keyword_assignment_validation_fixture_audit, $charset_collate));', self::$schema_source, 'Activation installs the audit table from the shared DDL.' );
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_validation_fixtures_schema_sql($table, $wpdb->get_charset_collate()));', self::$guard_source );
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_validation_fixture_audit_schema_sql($audit_table, $wpdb->get_charset_collate()));', self::$guard_source );
        $this->assertStringContainsString( 'tmw_kw_assignment_validation_schema_version', self::$guard_source );
        $this->assertStringContainsString( '$target_version = 2;', self::$guard_source, 'Schema v2 adds the concurrency keys and the audit table.' );
    }

    public function test_runtime_guard_is_behaviorally_idempotent(): void {
        $this->freshWpdb();

        $first = \TMWSEO\Engine\Schema::ensure_keyword_assignment_validation_fixture_schema();
        $this->assertTrue( $first );
        $this->assertSame( 2, $GLOBALS['_tmw_validation_dbdelta_calls'], 'First run creates both tables via dbDelta.' );
        $this->assertSame( 2, (int) get_option( 'tmw_kw_assignment_validation_schema_version', 0 ) );

        $second = \TMWSEO\Engine\Schema::ensure_keyword_assignment_validation_fixture_schema();
        $this->assertTrue( $second );
        $this->assertSame( 2, $GLOBALS['_tmw_validation_dbdelta_calls'], 'Second run early-returns without dbDelta.' );
    }

    public function test_runtime_guard_converts_existing_myisam_tables(): void {
        $wpdb = $this->freshWpdb( true, 'MyISAM' );

        $this->assertTrue( \TMWSEO\Engine\Schema::ensure_keyword_assignment_validation_fixture_schema() );
        $this->assertSame( 'InnoDB', $wpdb->validation_engine );
        $this->assertSame( 'InnoDB', $wpdb->audit_engine );
        $this->assertSame( 2, (int) get_option( 'tmw_kw_assignment_validation_schema_version', 0 ) );
    }

    public function test_failed_engine_conversion_does_not_mark_schema_ready(): void {
        $wpdb = $this->freshWpdb( true, 'MyISAM' );
        $wpdb->fail_engine_conversion = true;

        $this->assertFalse( \TMWSEO\Engine\Schema::ensure_keyword_assignment_validation_fixture_schema() );
        $this->assertSame( 0, (int) get_option( 'tmw_kw_assignment_validation_schema_version', 0 ) );
        $this->assertSame( 'MyISAM', $wpdb->validation_engine );
    }

    // ── No destructive statements, no backfill, no row writes ─────────────

    public function test_new_schema_code_contains_no_destructive_statements(): void {
        foreach ( [ 'DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM' ] as $destructive ) {
            $this->assertStringNotContainsString( $destructive, self::$guard_source, 'Destructive statement in new schema code: ' . $destructive );
            $this->assertStringNotContainsString( $destructive, self::$fixtures_ddl );
            $this->assertStringNotContainsString( $destructive, self::$audit_ddl );
        }
        // Pre-existing structures untouched by this PR.
        $this->assertStringContainsString( 'UNIQUE KEY keyword (keyword)', self::$schema_source );
        $this->assertStringContainsString( 'UNIQUE KEY assignment_key (assignment_key)', self::$schema_source );
        $this->assertStringContainsString( 'UNIQUE KEY review_key (review_key)', self::$schema_source );
    }

    public function test_schema_guard_performs_no_backfill_and_no_row_writes(): void {
        foreach ( [ 'INSERT INTO', 'INSERT IGNORE', 'REPLACE INTO', 'UPDATE ', 'SELECT keyword FROM' ] as $token ) {
            $this->assertStringNotContainsString( $token, self::$guard_source, 'Backfill-capable statement in schema guard: ' . $token );
        }
        $wpdb = $this->freshWpdb();
        \TMWSEO\Engine\Schema::ensure_keyword_assignment_validation_fixture_schema();
        \TMWSEO\Engine\Schema::ensure_keyword_assignment_validation_fixture_schema();
        $this->assertSame( [], $wpdb->mutations, 'Schema guard must not insert/update/delete any row.' );
        $this->assertSame( [], $wpdb->candidate_touches, 'Schema guard must not touch the candidates table at all.' );
    }

    // ── No execution on plugin load; no production cutover ────────────────

    public function test_loader_only_requires_validation_classes_and_never_instantiates_them(): void {
        $loader_source = (string) file_get_contents( __DIR__ . '/../includes/class-loader.php' );
        foreach ( [
            'class-keyword-assignment-validation-fixture-repository.php',
            'class-keyword-assignment-validation-service.php',
        ] as $file ) {
            $this->assertStringContainsString( "tmwseo_safe_require( \$p . '" . $file . "' );", $loader_source, 'Loader must safe-require ' . $file );
        }
        $this->assertStringNotContainsString( 'new KeywordAssignmentValidation', $loader_source, 'Loader never instantiates validation classes.' );

        $plugin_source = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
        $this->assertStringNotContainsString( 'KeywordAssignmentValidation', $plugin_source, 'Plugin bootstrap never touches the validation layer.' );
        $this->assertStringNotContainsString( 'ensure_keyword_assignment_validation_fixture_schema', $plugin_source, 'The fixture schema guard runs only from the explicit CLI workflow, never on plugin load.' );
    }

    public function test_validation_classes_referenced_only_in_sanctioned_files(): void {
        $root = realpath( __DIR__ . '/..' );
        $sanctioned = [
            'includes/keywords/class-keyword-assignment-validation-fixture-repository.php',
            'includes/keywords/class-keyword-assignment-validation-service.php',
            'includes/keywords/class-keyword-assignment-migration-service.php', // per-call override transform application only
            'includes/cli/class-cli.php',       // explicit operator workflow only
        ];
        $offenders = [];
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( 'php' !== strtolower( (string) $file->getExtension() ) ) { continue; }
            $relative = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( (string) $root ) + 1 ) );
            if ( str_starts_with( $relative, 'tests/' ) || str_starts_with( $relative, 'vendor/' ) || str_starts_with( $relative, 'node_modules/' ) ) { continue; }
            if ( in_array( $relative, $sanctioned, true ) ) { continue; }
            $source = (string) file_get_contents( (string) $file->getPathname() );
            if ( false !== strpos( $source, 'KeywordAssignmentValidation' ) ) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame( [], $offenders, 'Validation classes must not leak into production paths: ' . implode( ', ', $offenders ) );
    }

    // ── OPT-IN override contract (source-level proof) ─────────────────────

    public function test_stale_overrides_are_strictly_per_call_with_no_stored_state(): void {
        // The migration service holds NO override state and NO fixture
        // repository access: overrides are a per-call analyze() argument
        // that defaults to [] for every ordinary caller.
        $this->assertStringContainsString( 'public function analyze( array $filters = [], array $validation_stale_overrides = [] ): array', self::$migration_source );
        foreach ( [
            'set_validation_stale_overrides',
            'set_validation_fixture_repository',
            'load_validation_stale_overrides',
            'new KeywordAssignmentValidationFixtureRepository',
            'private ?array $validation_stale_overrides',
            'static $validation',
        ] as $forbidden ) {
            $this->assertStringNotContainsString( $forbidden, self::$migration_source, 'Leak-capable override plumbing must not exist: ' . $forbidden );
        }
        $this->assertSame( 1, substr_count( self::$migration_source, 'apply_stale_overrides_to_row' ), 'Exactly one application site.' );
        $this->assertStringContainsString( '$stale_overrides = $validation_stale_overrides;', self::$migration_source, 'The applied set is exactly the per-call argument.' );

        // The executor only forwards the explicit per-call argument.
        $this->assertStringContainsString( "string \$source = 'review-execute', array \$validation_stale_overrides = [] ): array", self::$execution_source );
        $this->assertStringContainsString( '$this->migration->analyze( $evidence_filters, $validation_stale_overrides );', self::$execution_source );
        $this->assertStringNotContainsString( 'KeywordAssignmentValidationFixtureRepository', self::$execution_source );

        // Snapshot hashes and production data are never touched by the
        // validation layer.
        foreach ( [ self::$repository_source, self::$service_source ] as $source ) {
            $this->assertStringNotContainsString( "'snapshot_hash' =>", $source, 'The validation layer must never write snapshot hashes.' );
            $this->assertStringNotContainsString( 'update_option', $source );
            $this->assertStringNotContainsString( 'update_post_meta', $source );
            $this->assertStringNotContainsString( 'rank_math_focus_keyword', $source );
            $this->assertStringNotContainsString( 'wp_update_post', $source );
        }
        // The only place overrides are built for the executor is the
        // verified run-stale-validation context.
        $this->assertStringContainsString( 'validation_context_review_mismatch', self::$service_source );
        $this->assertStringContainsString( 'validation_context_candidate_mismatch', self::$service_source );

        // Rev 3: external-transaction participation is confined to the
        // validation orchestration. The review repository gates its own
        // verbs, the service joins/leaves (leave in finally on every path),
        // and NO other production file ever joins — so every normal PR-E
        // caller keeps its real per-operation transactions.
        $review_repository_source = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-review-repository.php' );
        $this->assertStringContainsString( "if ( \$this->external_transaction ) { return true; }", $review_repository_source );
        $this->assertStringContainsString( 'public function join_external_transaction(): void', $review_repository_source );
        $this->assertSame( 2, substr_count( self::$service_source, 'join_external_transaction()' ), 'Exactly the two atomic units (recover + run) join.' );
        $this->assertSame( 2, substr_count( self::$service_source, "finally {" ), 'Participation ends in finally on every path.' );
        foreach ( [ self::$migration_source, self::$execution_source, self::$cli_source ] as $other_source ) {
            $this->assertStringNotContainsString( 'join_external_transaction', $other_source, 'No other production code may join an external transaction.' );
        }
    }

    public function test_validation_logging_is_gated_behind_debug_flags(): void {
        // One gated logging site per class; nothing logs unconditionally.
        $this->assertStringContainsString( 'TMWSEO_KW_VALIDATION_DEBUG', self::$repository_source );
        $this->assertSame( 1, substr_count( self::$repository_source, 'error_log(' ), 'Repository logs only inside its gated log().' );
        $this->assertStringContainsString( "if ( self::debug_logging_enabled() ) {\n            error_log(", self::$repository_source );
        $this->assertSame( 1, substr_count( self::$service_source, 'error_log(' ), 'Service logs only inside its gated log().' );
        $this->assertStringContainsString( 'KeywordAssignmentValidationFixtureRepository::debug_logging_enabled()', self::$service_source );
        // The schema guard logs only through its gated helper.
        $this->assertSame( 1, substr_count( self::$guard_source, 'error_log(' ), 'Schema guard logs only inside the gated helper.' );
        $this->assertStringContainsString( 'keyword_validation_debug_enabled()', self::$guard_source );
        $this->assertStringContainsString( 'TMWSEO_KW_VALIDATION_DEBUG', self::$guard_source );
    }

    public function test_cli_validation_command_is_explicit_and_dry_run_by_default(): void {
        $this->assertStringContainsString( "'create-manual-fixture', 'inspect-manual-fixture', 'remove-manual-fixture', 'recover-manual-review', 'create-stale-fixture', 'run-stale-validation', 'restore-stale-fixture', 'status'", self::$cli_source, 'Explicit action whitelist incl. the recovery and run actions.' );
        $this->assertStringContainsString( "[TMW-KW-ASSIGN-VALIDATE] Explicit action required.", self::$cli_source );
        // Dry-run default appears for BOTH the review executor and the
        // validation command.
        $this->assertGreaterThanOrEqual( 2, substr_count( self::$cli_source, "\$mode = (string) ( \$assoc['mode'] ?? 'dry-run' );" ) );
        $this->assertStringContainsString( "\$execute = 'execute' === \$mode;", self::$cli_source );
        // Schema guard runs inside the command, never on load.
        $this->assertStringContainsString( 'Schema::ensure_keyword_assignment_validation_fixture_schema()', self::$cli_source );
        // No broad/unbounded action exists: every mutating action requires a
        // token, and the stale/recovery workflows explicit IDs.
        $this->assertStringContainsString( "PRODUCTION-VALIDATION TOOLING ONLY", self::$cli_source );
        // Issue-7: the CLI itself rejects every non-secondary manual role.
        $this->assertStringContainsString( "if ( 'secondary' !== \$cli_role ) {", self::$cli_source );
        $this->assertStringContainsString( '--role must be secondary', self::$cli_source );
        // run-stale-validation carries the full validation context.
        $this->assertStringContainsString( "'review_id'    => (int) ( \$assoc['review-id'] ?? 0 ),", self::$cli_source );
        $this->assertStringContainsString( "'candidate_id' => (int) ( \$assoc['candidate-id'] ?? 0 ),", self::$cli_source );
    }

    public function test_fixture_rows_are_never_deleted_by_the_plugin(): void {
        foreach ( [ self::$repository_source, self::$service_source ] as $source ) {
            $this->assertStringNotContainsString( 'DELETE FROM', $source, 'Fixture bookkeeping is append-only + state transitions.' );
        }
        $this->assertStringNotContainsString( 'delete(', self::$repository_source, 'No wpdb->delete against the fixture tables.' );
        // The only deletion in the service is the single-row assignment
        // cleanup through the assignment repository's targeted method.
        $this->assertSame( 1, substr_count( self::$service_source, 'delete_assignment(' ), 'Exactly one targeted single-row deletion site.' );
        // Manual fixtures are secondary-only at the service layer too.
        $this->assertStringContainsString( "public const FIXTURE_ROLES = [ 'secondary' ];", self::$service_source );
        $this->assertStringContainsString( 'invalid_role_only_secondary_allowed', self::$service_source );
    }
}

// ── Fake wpdb for the schema guard (mirrors ReviewSchemaGuardWpdb) ────────

final class ValidationSchemaGuardWpdb {
    public string $prefix = 'wp_';
    public bool $validation_table_exists = false;
    public bool $audit_table_exists = false;
    public bool $candidates_table_exists = true;
    public string $validation_engine = 'InnoDB';
    public string $audit_engine = 'InnoDB';
    public bool $fail_engine_conversion = false;
    public string $last_error = '';
    /** @var array<int,string> */
    public array $mutations = [];
    /** @var array<int,string> */
    public array $candidate_touches = [];

    public function prepare( string $query, ...$args ) {
        foreach ( $args as $arg ) {
            $query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'", $query, 1 );
        }
        return $query;
    }
    public function esc_like( string $text ): string { return $text; }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

    private function is_audit( string $sql ): bool {
        return false !== stripos( str_replace( '\\', '', $sql ), 'tmw_keyword_assignment_validation_fixture_audit' );
    }

    public function get_var( string $sql ) {
        $clean = str_replace( '\\', '', $sql );
        if ( false !== stripos( $clean, 'tmw_keyword_candidates' ) && false === stripos( $clean, 'SHOW TABLES' ) ) {
            $this->candidate_touches[] = $sql;
        }
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
            if ( $this->is_audit( $sql ) ) {
                return $this->audit_table_exists ? $this->prefix . 'tmw_keyword_assignment_validation_fixture_audit' : null;
            }
            if ( false !== stripos( $clean, 'tmw_keyword_assignment_validation_fixtures' ) ) {
                return $this->validation_table_exists ? $this->prefix . 'tmw_keyword_assignment_validation_fixtures' : null;
            }
            if ( false !== stripos( $clean, 'tmw_keyword_candidates' ) ) {
                return $this->candidates_table_exists ? $this->prefix . 'tmw_keyword_candidates' : null;
            }
        }
        return null;
    }
    public function get_row( string $sql, string $output = 'OBJECT' ) {
        if ( false !== stripos( $sql, 'SHOW TABLE STATUS LIKE' ) ) {
            if ( $this->is_audit( $sql ) && $this->audit_table_exists ) {
                return [ 'Engine' => $this->audit_engine ];
            }
            if ( ! $this->is_audit( $sql ) && $this->validation_table_exists ) {
                return [ 'Engine' => $this->validation_engine ];
            }
        }
        return null;
    }
    public function get_results( string $sql, string $output = 'OBJECT' ): array { return []; }
    public function get_col( string $sql ): array { return []; }
    public function query( string $sql ) {
        if ( false !== stripos( $sql, 'ENGINE=InnoDB' ) && false !== stripos( $sql, 'ALTER TABLE' ) ) {
            if ( $this->fail_engine_conversion ) { $this->last_error = 'conversion failed'; return false; }
            if ( $this->is_audit( $sql ) ) { $this->audit_engine = 'InnoDB'; } else { $this->validation_engine = 'InnoDB'; }
            return 1;
        }
        if ( preg_match( '/^(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP)/i', trim( $sql ) ) ) {
            $this->mutations[] = $sql;
        }
        return 0;
    }
    public function insert( ...$args ) { $this->mutations[] = 'insert'; return false; }
    public function update( string $table, ...$args ) {
        $this->mutations[] = 'update:' . $table;
        if ( false !== stripos( $table, 'tmw_keyword_candidates' ) ) { $this->candidate_touches[] = 'update'; }
        return false;
    }
    public function delete( ...$args ) { $this->mutations[] = 'delete'; return false; }
}

// dbDelta stub (counts calls, flips table existence). Cooperative: whichever
// schema test file loads first defines dbDelta for the whole process, so this
// stub also serves the PR-C and PR-E schema guard tests when they run later.
$GLOBALS['_tmw_validation_dbdelta_calls'] = 0;
if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $queries = '', $execute = true ) {
        $GLOBALS['_tmw_review_dbdelta_calls'] = ( $GLOBALS['_tmw_review_dbdelta_calls'] ?? 0 ) + 1;
        $GLOBALS['_tmw_assignment_dbdelta_calls'] = ( $GLOBALS['_tmw_assignment_dbdelta_calls'] ?? 0 ) + 1;
        $GLOBALS['_tmw_validation_dbdelta_calls'] = ( $GLOBALS['_tmw_validation_dbdelta_calls'] ?? 0 ) + 1;
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ( $wpdb instanceof ValidationSchemaGuardWpdb ) {
            if ( is_string( $queries ) && false !== stripos( $queries, 'tmw_keyword_assignment_validation_fixture_audit' ) ) {
                $wpdb->audit_table_exists = true;
            } else {
                $wpdb->validation_table_exists = true;
            }
        }
        if ( null !== $wpdb && class_exists( 'ReviewSchemaGuardWpdb', false ) && $wpdb instanceof ReviewSchemaGuardWpdb ) {
            $wpdb->review_tables_exist = true;
        }
        if ( null !== $wpdb && class_exists( 'AssignmentSchemaGuardWpdb', false ) && $wpdb instanceof AssignmentSchemaGuardWpdb ) {
            $wpdb->assignments_table_exists = true;
        }
        return [];
    }
}
