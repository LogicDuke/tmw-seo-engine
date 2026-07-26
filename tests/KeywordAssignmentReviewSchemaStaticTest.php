<?php
/**
 * PR-E — review schema and production-safety tests.
 *
 * Schema: DDL validity for both review tables, shared single DDL source
 * between installer and runtime guard, behavioral idempotency, InnoDB
 * conversion, no destructive statements, no backfill, no candidate mutation.
 *
 * Safety: proves the review layer executes nothing on plugin load and that
 * no production path (approval, rejection, generation, Rank Math,
 * publishing, resolver) references the review classes — only the loader
 * (require only), the schema guard, and the explicit CLI workflow do. Also
 * proves the audit table is append-only across the whole plugin.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KeywordAssignmentReviewSchemaStaticTest extends TestCase {

    private static string $schema_source = '';
    private static string $review_ddl = '';
    private static string $audit_ddl = '';
    private static string $guard_source = '';
    private static int $prefix_counter = 0;

    private $original_wpdb;

    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../includes/db/class-schema.php';
        self::$schema_source = (string) file_get_contents( __DIR__ . '/../includes/db/class-schema.php' );
        $start = strpos( self::$schema_source, 'public static function get_keyword_assignment_review_schema_sql' );
        $end   = strpos( self::$schema_source, 'private static function safe_sql_hash' );
        self::$guard_source = ( false !== $start && false !== $end ) ? substr( self::$schema_source, $start, $end - $start ) : '';
        self::$review_ddl = \TMWSEO\Engine\Schema::get_keyword_assignment_review_schema_sql( 'wp_tmw_keyword_assignment_review', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
        self::$audit_ddl  = \TMWSEO\Engine\Schema::get_keyword_assignment_review_audit_schema_sql( 'wp_tmw_keyword_assignment_review_audit', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
    }

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['_tmw_test_options'] = [];
        $GLOBALS['_tmw_review_dbdelta_calls'] = 0;
        $this->assertNotSame( '', self::$guard_source, 'Guard source extraction failed.' );
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    /** Fresh fake wpdb with a unique prefix (defeats the table-exists cache). */
    private function freshWpdb( bool $exists = false, string $engine = 'InnoDB' ): ReviewSchemaGuardWpdb {
        $wpdb = new ReviewSchemaGuardWpdb();
        $wpdb->prefix = 'wpr' . ( ++self::$prefix_counter ) . '_';
        $wpdb->review_tables_exist = $exists;
        $wpdb->review_engine = $engine;
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    // ── DDL validity ──────────────────────────────────────────────────────

    public function test_review_table_sql_is_structurally_valid(): void {
        $ddl = self::$review_ddl;
        $this->assertStringStartsWith( 'CREATE TABLE wp_tmw_keyword_assignment_review (', $ddl );
        $this->assertSame( substr_count( $ddl, '(' ), substr_count( $ddl, ')' ), 'Balanced parentheses.' );
        $this->assertStringContainsString( 'PRIMARY KEY (id)', $ddl );
        $this->assertStringContainsString( 'UNIQUE KEY review_key (review_key)', $ddl );
        $this->assertStringContainsString( ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4', $ddl );
        $this->assertStringNotContainsString( 'FOREIGN KEY', $ddl, 'No FKs, matching every existing keyword table.' );
        $this->assertStringNotContainsString( 'GENERATED', $ddl );
        $this->assertStringNotContainsString( ' AS (', $ddl );
        foreach ( [
            'review_key CHAR(40) NOT NULL',
            'migration_version VARCHAR(30) NOT NULL',
            'keyword_candidate_id BIGINT(20) UNSIGNED NOT NULL',
            'classification VARCHAR(40) NOT NULL',
            'target_key VARCHAR(191) NOT NULL',
            'planned_canonical_owner TINYINT(1) NOT NULL DEFAULT 0',
            'snapshot_hash CHAR(40) NOT NULL',
            'report_only TINYINT(1) NOT NULL DEFAULT 0',
            "review_state VARCHAR(20) NOT NULL DEFAULT 'pending'",
            "execution_state VARCHAR(20) NOT NULL DEFAULT 'not_executed'",
            'stale_reason VARCHAR(500) NOT NULL',
            'created_at DATETIME NOT NULL',
            'updated_at DATETIME NOT NULL',
        ] as $fragment ) {
            $this->assertStringContainsString( $fragment, $ddl, 'Missing DDL fragment: ' . $fragment );
        }
        foreach ( [
            'KEY candidate (keyword_candidate_id)',
            'KEY review_exec_state (review_state, execution_state)',
            'KEY classification (classification)',
            'KEY pool_target (pool, target_type, target_id)',
            'KEY target_key (target_key)',
            'KEY migration_version (migration_version)',
            'KEY source_batch_id (source_batch_id)',
        ] as $index ) {
            $this->assertStringContainsString( $index, $ddl, 'Missing index: ' . $index );
        }
    }

    public function test_audit_table_sql_is_structurally_valid(): void {
        $ddl = self::$audit_ddl;
        $this->assertStringStartsWith( 'CREATE TABLE wp_tmw_keyword_assignment_review_audit (', $ddl );
        $this->assertSame( substr_count( $ddl, '(' ), substr_count( $ddl, ')' ), 'Balanced parentheses.' );
        $this->assertStringContainsString( 'PRIMARY KEY (id)', $ddl );
        $this->assertStringNotContainsString( 'FOREIGN KEY', $ddl );
        foreach ( [
            'review_id BIGINT(20) UNSIGNED NOT NULL',
            'review_key CHAR(40) NOT NULL',
            'action VARCHAR(40) NOT NULL',
            'old_review_state VARCHAR(20)',
            'new_review_state VARCHAR(20)',
            'old_execution_state VARCHAR(20)',
            'new_execution_state VARCHAR(20)',
            'actor VARCHAR(191)',
            'snapshot_hash CHAR(40)',
            'created_at DATETIME NOT NULL',
        ] as $fragment ) {
            $this->assertStringContainsString( $fragment, $ddl, 'Missing DDL fragment: ' . $fragment );
        }
    }

    // ── Installer/guard wiring and idempotency ────────────────────────────

    public function test_installer_and_runtime_guard_share_one_ddl_source(): void {
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_review_schema_sql($keyword_assignment_review, $charset_collate));', self::$schema_source, 'Activation installs the review table from the shared DDL.' );
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_review_audit_schema_sql($keyword_assignment_review_audit, $charset_collate));', self::$schema_source, 'Activation installs the audit table from the shared DDL.' );
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_review_schema_sql($review_table, $charset_collate));', self::$guard_source );
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignment_review_audit_schema_sql($audit_table, $charset_collate));', self::$guard_source );
        $this->assertStringContainsString( 'tmw_keyword_assignment_review_schema_version', self::$guard_source );

        $plugin_source = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
        $this->assertStringContainsString( "add_action('admin_init', [Schema::class, 'ensure_keyword_assignment_review_schema']);", $plugin_source );
        $this->assertStringContainsString( 'Schema::ensure_keyword_assignment_review_schema();', $plugin_source );
    }

    public function test_runtime_guard_is_behaviorally_idempotent(): void {
        $this->freshWpdb();

        $first = \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema();
        $this->assertTrue( $first );
        $this->assertSame( 2, $GLOBALS['_tmw_review_dbdelta_calls'], 'First run creates both tables via dbDelta.' );
        $this->assertSame( 1, (int) get_option( 'tmw_keyword_assignment_review_schema_version', 0 ) );

        $second = \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema();
        $this->assertTrue( $second );
        $this->assertSame( 2, $GLOBALS['_tmw_review_dbdelta_calls'], 'Second run early-returns without dbDelta.' );
    }

    public function test_runtime_guard_converts_existing_myisam_tables(): void {
        $wpdb = $this->freshWpdb( true, 'MyISAM' );

        $this->assertTrue( \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema() );
        $this->assertSame( 'InnoDB', $wpdb->review_engine );
        $this->assertSame( 1, (int) get_option( 'tmw_keyword_assignment_review_schema_version', 0 ) );
    }

    public function test_failed_engine_conversion_does_not_mark_schema_ready(): void {
        $wpdb = $this->freshWpdb( true, 'MyISAM' );
        $wpdb->fail_engine_conversion = true;

        $this->assertFalse( \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema() );
        $this->assertSame( 0, (int) get_option( 'tmw_keyword_assignment_review_schema_version', 0 ) );
        $this->assertSame( 'MyISAM', $wpdb->review_engine );
    }

    // ── No destructive statements, no backfill, no candidate writes ───────

    public function test_new_schema_code_contains_no_destructive_statements(): void {
        foreach ( [ 'DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM' ] as $destructive ) {
            $this->assertStringNotContainsString( $destructive, self::$guard_source, 'Destructive statement in new schema code: ' . $destructive );
            $this->assertStringNotContainsString( $destructive, self::$review_ddl );
            $this->assertStringNotContainsString( $destructive, self::$audit_ddl );
        }
        // Pre-existing structures untouched by this PR.
        $this->assertStringContainsString( 'UNIQUE KEY keyword (keyword)', self::$schema_source );
        $this->assertStringContainsString( 'UNIQUE KEY assignment_key (assignment_key)', self::$schema_source );
    }

    public function test_schema_guard_performs_no_backfill_and_no_row_writes(): void {
        foreach ( [ 'INSERT INTO', 'INSERT IGNORE', 'REPLACE INTO', 'UPDATE ', 'SELECT keyword FROM' ] as $token ) {
            $this->assertStringNotContainsString( $token, self::$guard_source, 'Backfill-capable statement in schema guard: ' . $token );
        }
        $wpdb = $this->freshWpdb();
        \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema();
        \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema();
        $this->assertSame( [], $wpdb->mutations, 'Schema guard must not insert/update/delete any row.' );
        $this->assertSame( [], $wpdb->candidate_touches, 'Schema guard must not touch the candidates table at all.' );
    }

    // ── No execution on plugin load; no production cutover ────────────────

    public function test_loader_only_requires_review_classes_and_never_instantiates_them(): void {
        $loader_source = (string) file_get_contents( __DIR__ . '/../includes/class-loader.php' );
        foreach ( [
            'class-keyword-assignment-review-repository.php',
            'class-keyword-assignment-review-sync-service.php',
            'class-keyword-assignment-review-execution-service.php',
            'class-keyword-assignment-review-export-service.php',
        ] as $file ) {
            $this->assertStringContainsString( "tmwseo_safe_require( \$p . '" . $file . "' );", $loader_source, 'Loader must safe-require ' . $file );
        }
        $this->assertStringNotContainsString( 'new KeywordAssignmentReview', $loader_source, 'Loader never instantiates review classes.' );

        $plugin_source = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
        $this->assertStringNotContainsString( 'KeywordAssignmentReviewSyncService', $plugin_source, 'Plugin bootstrap never syncs.' );
        $this->assertStringNotContainsString( 'KeywordAssignmentReviewExecutionService', $plugin_source, 'Plugin bootstrap never executes.' );
    }

    public function test_review_classes_referenced_only_in_sanctioned_files(): void {
        $root = realpath( __DIR__ . '/..' );
        $sanctioned = [
            'includes/keywords/class-keyword-assignment-review-repository.php',
            'includes/keywords/class-keyword-assignment-review-sync-service.php',
            'includes/keywords/class-keyword-assignment-review-execution-service.php',
            'includes/keywords/class-keyword-assignment-review-export-service.php',
            'includes/cli/class-cli.php',      // explicit operator workflow only
            'includes/class-loader.php',        // require only (asserted above)
        ];
        $offenders = [];
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( 'php' !== strtolower( (string) $file->getExtension() ) ) { continue; }
            $relative = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( (string) $root ) + 1 ) );
            if ( str_starts_with( $relative, 'tests/' ) || str_starts_with( $relative, 'vendor/' ) || str_starts_with( $relative, 'node_modules/' ) ) { continue; }
            if ( in_array( $relative, $sanctioned, true ) ) { continue; }
            $source = (string) file_get_contents( (string) $file->getPathname() );
            if ( false !== strpos( $source, 'KeywordAssignmentReview' ) ) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame( [], $offenders, 'Review classes must not leak into production paths: ' . implode( ', ', $offenders ) );
    }

    public function test_audit_table_is_append_only_across_the_plugin(): void {
        $root = realpath( __DIR__ . '/..' );
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( 'php' !== strtolower( (string) $file->getExtension() ) ) { continue; }
            $source = (string) file_get_contents( (string) $file->getPathname() );
            if ( false === strpos( $source, 'review_audit' ) && false === strpos( $source, 'audit_table' ) ) { continue; }
            // No UPDATE/DELETE against the audit table anywhere: the only
            // storage verbs allowed near it are insert and select.
            $this->assertStringNotContainsString( 'update( $this->audit_table()', $source );
            $this->assertStringNotContainsString( 'delete( $this->audit_table()', $source );
            $this->assertStringNotContainsString( 'DELETE FROM {$this->audit_table()}', $source );
            $this->assertStringNotContainsString( 'UPDATE {$this->audit_table()}', $source );
        }
        // The repository exposes no method that mutates audit rows.
        require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php';
        require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-migration-analyzer.php';
        require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-review-repository.php';
        $methods = get_class_methods( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::class );
        foreach ( $methods as $method ) {
            $this->assertDoesNotMatchRegularExpression( '/^(update|delete).*audit/i', $method, 'No audit mutation methods.' );
        }
    }

    public function test_cli_execution_is_dry_run_by_default_and_mutations_need_confirmation(): void {
        $cli_source = (string) file_get_contents( __DIR__ . '/../includes/cli/class-cli.php' );
        // No default action: an explicit action word is required.
        $this->assertStringContainsString( "Explicit action required.", $cli_source );
        // Execution requires --mode=execute; the default is dry-run.
        $this->assertStringContainsString( "\$mode = (string) ( \$assoc['mode'] ?? 'dry-run' );", $cli_source );
        $this->assertStringContainsString( "\$service->execute_approved( \$filters, 'execute' === \$mode", $cli_source );
        // Filtered bulk mutation demands --confirm, and unbounded selections
        // additionally demand --all-matching.
        $this->assertStringContainsString( 'requires --confirm', $cli_source );
        $this->assertStringContainsString( '--all-matching', $cli_source );
        // Export refuses unsafe extensions.
        $this->assertStringContainsString( 'must end in .json or .csv', $cli_source );
    }
}

/** Minimal fake wpdb for the review schema guard. */
final class ReviewSchemaGuardWpdb {
    public string $prefix = 'wp_';
    public bool $review_tables_exist = false;
    public bool $candidates_table_exists = true;
    public string $review_engine = 'InnoDB';
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

    public function get_var( string $sql ) {
        $clean = str_replace( '\\', '', $sql );
        if ( false !== stripos( $clean, 'tmw_keyword_candidates' ) && false === stripos( $clean, 'SHOW TABLES' ) ) {
            $this->candidate_touches[] = $sql;
        }
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
            if ( false !== stripos( $clean, 'tmw_keyword_assignment_review' ) ) {
                if ( ! $this->review_tables_exist ) { return null; }
                return false !== stripos( $clean, '_audit' )
                    ? $this->prefix . 'tmw_keyword_assignment_review_audit'
                    : $this->prefix . 'tmw_keyword_assignment_review';
            }
            if ( false !== stripos( $clean, 'tmw_keyword_candidates' ) ) {
                return $this->candidates_table_exists ? $this->prefix . 'tmw_keyword_candidates' : null;
            }
        }
        return null;
    }
    public function get_row( string $sql, string $output = 'OBJECT' ) {
        if ( false !== stripos( $sql, 'SHOW TABLE STATUS LIKE' ) && $this->review_tables_exist ) {
            return [ 'Engine' => $this->review_engine ];
        }
        return null;
    }
    public function get_results( string $sql, string $output = 'OBJECT' ): array { return []; }
    public function get_col( string $sql ): array { return []; }
    public function query( string $sql ) {
        if ( false !== stripos( $sql, 'ENGINE=InnoDB' ) && false !== stripos( $sql, 'ALTER TABLE' ) ) {
            if ( $this->fail_engine_conversion ) { $this->last_error = 'conversion failed'; return false; }
            $this->review_engine = 'InnoDB';
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
// stub also serves the PR-C assignment schema guard test when it runs later.
$GLOBALS['_tmw_review_dbdelta_calls'] = 0;
if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $queries = '', $execute = true ) {
        $GLOBALS['_tmw_review_dbdelta_calls'] = ( $GLOBALS['_tmw_review_dbdelta_calls'] ?? 0 ) + 1;
        $GLOBALS['_tmw_assignment_dbdelta_calls'] = ( $GLOBALS['_tmw_assignment_dbdelta_calls'] ?? 0 ) + 1;
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ( $wpdb instanceof ReviewSchemaGuardWpdb ) {
            $wpdb->review_tables_exist = true;
        }
        if ( null !== $wpdb && class_exists( 'AssignmentSchemaGuardWpdb', false ) && $wpdb instanceof AssignmentSchemaGuardWpdb ) {
            $wpdb->assignments_table_exists = true;
        }
        return [];
    }
}
