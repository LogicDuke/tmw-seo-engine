<?php
/**
 * PR-C — assignment schema and production-safety tests.
 *
 * Schema: DDL validity, shared single DDL source between installer and
 * runtime guard, idempotency, no destructive statements, no backfill, no
 * candidate mutation during schema installation.
 *
 * Safety: proves no current production path (approval, rejection,
 * generation, Rank Math, resolver) reads or writes the assignment layer yet.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KeywordAssignmentSchemaStaticTest extends TestCase {

    private static string $schema_source = '';
    private static string $ddl = '';
    private static string $guard_source = '';

    private $original_wpdb;

    public static function setUpBeforeClass(): void {
        self::$schema_source = (string) file_get_contents( __DIR__ . '/../includes/db/class-schema.php' );
        $start = strpos( self::$schema_source, 'public static function get_keyword_assignments_schema_sql' );
        $end   = strpos( self::$schema_source, 'private static function safe_sql_hash' );
        self::$guard_source = ( false !== $start && false !== $end ) ? substr( self::$schema_source, $start, $end - $start ) : '';
        if ( class_exists( '\TMWSEO\Engine\Schema' ) && method_exists( '\TMWSEO\Engine\Schema', 'get_keyword_assignments_schema_sql' ) ) {
            self::$ddl = \TMWSEO\Engine\Schema::get_keyword_assignments_schema_sql( 'wp_tmw_keyword_assignments', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
        }
    }

    protected function setUp(): void {
        parent::setUp();
        require_once __DIR__ . '/../includes/db/class-schema.php';
        if ( '' === self::$ddl ) {
            self::$ddl = \TMWSEO\Engine\Schema::get_keyword_assignments_schema_sql( 'wp_tmw_keyword_assignments', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
        }
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['_tmw_test_options'] = [];
        $GLOBALS['_tmw_assignment_dbdelta_calls'] = 0;
        $this->assertNotSame( '', self::$guard_source, 'Guard source extraction failed.' );
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ── 1. DDL validity for supported MySQL/MariaDB ───────────────────────

    public function test_table_sql_is_structurally_valid(): void {
        $ddl = self::$ddl;
        $this->assertStringStartsWith( 'CREATE TABLE wp_tmw_keyword_assignments (', $ddl );
        $this->assertSame( substr_count( $ddl, '(' ), substr_count( $ddl, ')' ), 'Balanced parentheses.' );
        $this->assertStringContainsString( 'PRIMARY KEY (id)', $ddl );
        $this->assertStringContainsString( 'UNIQUE KEY assignment_key (assignment_key)', $ddl );
        $this->assertStringContainsString( ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4', $ddl );
        // No Postgres-style partial unique index and no generated columns
        // (both unsupported by dbDelta / older MariaDB).
        $this->assertStringNotContainsString( 'WHERE', $ddl );
        $this->assertStringNotContainsString( 'GENERATED', $ddl );
        $this->assertStringNotContainsString( ' AS (', $ddl );
        // No foreign keys — the plugin's keyword tables consistently avoid them.
        $this->assertStringNotContainsString( 'FOREIGN KEY', $ddl );
        // Index-safe varchar widths under utf8mb4 (191 for indexed keys).
        $this->assertStringContainsString( 'target_key VARCHAR(191) NOT NULL', $ddl );
        // Every required field group exists.
        foreach ( [
            'keyword_candidate_id BIGINT(20) UNSIGNED NOT NULL',
            'assignment_key CHAR(40) NOT NULL',
            'pool VARCHAR(30) NOT NULL',
            'page_type VARCHAR(50) NOT NULL',
            "role VARCHAR(20) NOT NULL DEFAULT 'secondary'",
            "status VARCHAR(30) NOT NULL DEFAULT 'review_required'",
            'canonical_owner TINYINT(1) NOT NULL DEFAULT 0',
            'shared_secondary_allowed TINYINT(1) NOT NULL DEFAULT 0',
            'active_in_rank_math TINYINT(1) NOT NULL DEFAULT 0',
            'present_in_content TINYINT(1) NOT NULL DEFAULT 0',
            'source_batch_id BIGINT(20) UNSIGNED NULL',
            'source_import_row_id BIGINT(20) UNSIGNED NULL',
            'last_verified_at DATETIME NULL',
            'created_at DATETIME NOT NULL',
            'updated_at DATETIME NOT NULL',
        ] as $fragment ) {
            $this->assertStringContainsString( $fragment, $ddl, 'Missing DDL fragment: ' . $fragment );
        }
        foreach ( [
            'KEY candidate (keyword_candidate_id)',
            'KEY candidate_owner (keyword_candidate_id, canonical_owner, status)',
            'KEY candidate_target (keyword_candidate_id, target_type, target_id)',
            'KEY target_lookup (pool, page_type, target_type, target_id)',
            'KEY target_key (target_key)',
            'KEY role_status (role, status)',
            'KEY status (status)',
            'KEY source_batch_id (source_batch_id)',
        ] as $index ) {
            $this->assertStringContainsString( $index, $ddl, 'Missing index: ' . $index );
        }
    }

    // ── 2–4. Idempotency and install/upgrade wiring ───────────────────────

    public function test_installer_and_runtime_guard_share_one_ddl_source(): void {
        // Install path (activation).
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignments_schema_sql($keyword_assignments, $charset_collate));', self::$schema_source );
        // Upgrade path (runtime guard) uses the SAME method, so the two can
        // never drift and dbDelta re-runs are no-ops on an existing table.
        $this->assertStringContainsString( 'dbDelta(self::get_keyword_assignments_schema_sql($table, $wpdb->get_charset_collate()));', self::$guard_source );
        // Guard early-returns via version option once the table exists.
        $this->assertStringContainsString( "tmw_keyword_assignments_schema_version", self::$guard_source );
        // Both activation and admin_init are wired in class-plugin.php.
        $plugin_source = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
        $this->assertStringContainsString( "add_action('admin_init', [Schema::class, 'ensure_keyword_assignments_schema']);", $plugin_source );
        $this->assertStringContainsString( 'Schema::ensure_keyword_assignments_schema();', $plugin_source );
    }

    public function test_runtime_guard_is_behaviorally_idempotent(): void {
        $wpdb = new AssignmentSchemaGuardWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $first = \TMWSEO\Engine\Schema::ensure_keyword_assignments_schema();
        $this->assertTrue( $first );
        $this->assertSame( 1, $GLOBALS['_tmw_assignment_dbdelta_calls'], 'First run creates via dbDelta.' );
        $this->assertSame( 1, (int) get_option( 'tmw_keyword_assignments_schema_version', 0 ) );

        $second = \TMWSEO\Engine\Schema::ensure_keyword_assignments_schema();
        $this->assertTrue( $second );
        $this->assertSame( 1, $GLOBALS['_tmw_assignment_dbdelta_calls'], 'Second run early-returns without dbDelta.' );
    }

    public function test_runtime_guard_converts_existing_myisam_table(): void {
        $wpdb = new AssignmentSchemaGuardWpdb();
        $wpdb->assignments_table_exists = true;
        $wpdb->assignments_engine = 'MyISAM';
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertTrue( \TMWSEO\Engine\Schema::ensure_keyword_assignments_schema() );
        $this->assertSame( 'InnoDB', $wpdb->assignments_engine );
        $this->assertSame( 1, (int) get_option( 'tmw_keyword_assignments_schema_version', 0 ) );
    }

    public function test_failed_engine_conversion_does_not_mark_schema_ready(): void {
        $wpdb = new AssignmentSchemaGuardWpdb();
        $wpdb->assignments_table_exists = true;
        $wpdb->assignments_engine = 'MyISAM';
        $wpdb->fail_engine_conversion = true;
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertFalse( \TMWSEO\Engine\Schema::ensure_keyword_assignments_schema() );
        $this->assertSame( 0, (int) get_option( 'tmw_keyword_assignments_schema_version', 0 ) );
        $this->assertSame( 'MyISAM', $wpdb->assignments_engine );
    }

    // ── 5. No destructive statements in the new schema code ───────────────

    public function test_new_schema_code_contains_no_destructive_statements(): void {
        foreach ( [ 'DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM' ] as $destructive ) {
            $this->assertStringNotContainsString( $destructive, self::$guard_source, 'Destructive statement in new schema code: ' . $destructive );
            $this->assertStringNotContainsString( $destructive, self::$ddl );
        }
        // The candidate table's unique keyword constraint is untouched.
        $this->assertStringContainsString( 'UNIQUE KEY keyword (keyword)', self::$schema_source );
    }

    // ── 6, 7, 32. No backfill; no candidate rows changed by installation ──

    public function test_schema_installation_performs_no_backfill_and_no_candidate_writes(): void {
        foreach ( [ 'INSERT INTO', 'INSERT IGNORE', 'REPLACE INTO', 'UPDATE ', 'SELECT keyword FROM' ] as $token ) {
            $this->assertStringNotContainsString( $token, self::$guard_source, 'Backfill-capable statement in schema guard: ' . $token );
        }
        $wpdb = new AssignmentSchemaGuardWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        \TMWSEO\Engine\Schema::ensure_keyword_assignments_schema();
        \TMWSEO\Engine\Schema::ensure_keyword_assignments_schema();
        $this->assertSame( [], $wpdb->mutations, 'Schema guard must not insert/update/delete any row.' );
        $this->assertSame( [], $wpdb->candidate_touches, 'Schema guard must not touch the candidates table at all.' );
    }

    // ── 27 & 28. Approval/rejection paths do not call the assignment layer ─

    public function test_manual_approval_and_rejection_paths_do_not_use_assignments(): void {
        $admin_source = (string) file_get_contents( __DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php' );
        $import_service = (string) file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php' );
        foreach ( [ $admin_source, $import_service ] as $source ) {
            $this->assertStringNotContainsString( 'KeywordAssignmentRepository', $source );
            $this->assertStringNotContainsString( 'tmw_keyword_assignments', $source );
        }
    }

    // ── 29 & 30. Generation and Rank Math do not read assignments yet ─────

    public function test_generation_and_rank_math_paths_do_not_read_assignments(): void {
        foreach ( [
            '/../includes/content/class-content-engine.php',
            '/../includes/keywords/class-category-approved-keyword-resolver.php',
            '/../includes/keywords/class-category-page-keyword-generator.php',
            '/../includes/content/class-index-readiness-gate.php',
        ] as $path ) {
            $source = (string) file_get_contents( __DIR__ . $path );
            $this->assertStringNotContainsString( 'KeywordAssignmentRepository', $source, 'Assignment reference in ' . $path );
            $this->assertStringNotContainsString( 'tmw_keyword_assignments', $source, 'Assignment table reference in ' . $path );
        }
    }

    // ── 31. Only sanctioned files reference the assignment layer at all ───

    public function test_only_sanctioned_files_reference_the_assignment_layer(): void {
        $sanctioned = [
            'includes/db/class-schema.php',
            'includes/class-plugin.php',
            'includes/class-loader.php',
            'includes/keywords/class-keyword-assignment-repository.php',
            'includes/keywords/class-keyword-ownership-report-service.php',
            // PR-D: migration infrastructure (dry-run default; no production cutover).
            'includes/keywords/class-keyword-assignment-migration-analyzer.php',
            'includes/keywords/class-keyword-assignment-migration-service.php',
            'includes/cli/class-cli.php',
        ];
        $offenders = [];
        $root = (string) realpath( dirname( __DIR__ ) );
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( (string) realpath( __DIR__ . '/../includes' ), \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( 'php' !== strtolower( (string) $file->getExtension() ) ) { continue; }
            $relative = str_replace( '\\', '/', substr( (string) realpath( (string) $file->getPathname() ), strlen( $root ) + 1 ) );
            $source = (string) file_get_contents( (string) $file->getPathname() );
            if ( false !== strpos( $source, 'tmw_keyword_assignments' ) || false !== strpos( $source, 'KeywordAssignmentRepository' ) ) {
                if ( ! in_array( $relative, $sanctioned, true ) ) {
                    $offenders[] = $relative;
                }
            }
        }
        $this->assertSame( [], $offenders, 'Unsanctioned production files reference the assignment layer.' );
    }

    // ── Report extension stays read-only and fail-safe ────────────────────

    public function test_ownership_report_assignment_diagnostics_fail_safe_when_table_missing(): void {
        require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
        require_once __DIR__ . '/../includes/keywords/class-keyword-ownership-report-service.php';
        $wpdb = new AssignmentSchemaGuardWpdb();
        $wpdb->assignments_table_exists = false;
        $wpdb->candidates_table_exists = false;
        $GLOBALS['wpdb'] = $wpdb;

        $service = new \TMWSEO\Engine\Keywords\KeywordOwnershipReportService();
        $summary = $service->summary();

        $this->assertSame( 0, $summary['assignments_table_present'] );
        $this->assertSame( 0, $summary['assignment_count'] );
        $this->assertSame( 0, $summary['primary_owner_violations'] );
        $this->assertSame( [], $wpdb->mutations, 'Diagnostics must be read-only.' );
    }

    // ── No hardcoding in any PR-C code ────────────────────────────────────

    public function test_no_category_specific_hardcoding_in_schema_code(): void {
        foreach ( [ 'Free Cam Chat', 'Live Cam Chat', 'live jasmin', 'livejasmin' ] as $forbidden ) {
            $this->assertFalse( stripos( self::$guard_source, $forbidden ) );
            $this->assertFalse( stripos( self::$ddl, $forbidden ) );
        }
    }

    public function test_migration_cli_validates_pool_and_fails_on_serialization_error(): void {
        $source = (string) file_get_contents( __DIR__ . '/../includes/cli/class-cli.php' );

        $this->assertStringContainsString( "[ 'category', 'model', 'video' ]", $source );
        $this->assertStringContainsString( '--pool must be one of: category, model, video.', $source );
        $this->assertStringContainsString( '$service->serialization_error()', $source );
        $this->assertStringContainsString( 'Report serialization failed:', $source );
    }
}

/**
 * wpdb stub for the schema guard: SHOW TABLES reflects a creatable table,
 * records any row-level mutation, and flags candidate-table contact.
 */
final class AssignmentSchemaGuardWpdb {
    public string $prefix = 'wp_';
    public bool $assignments_table_exists = false;
    public bool $candidates_table_exists = true;
    public string $assignments_engine = 'InnoDB';
    public bool $fail_engine_conversion = false;
    public string $last_error = '';
    /** @var array<int,string> */
    public array $mutations = [];
    /** @var array<int,string> */
    public array $candidate_touches = [];

    public function prepare( string $sql, ...$args ): string {
        $i = 0;
        return (string) preg_replace_callback( '/%[sdf]/', function () use ( $args, &$i ) {
            $value = $args[ $i++ ] ?? '';
            return is_string( $value ) ? "'" . addslashes( $value ) . "'" : (string) $value;
        }, $sql );
    }
    public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

    public function get_var( string $sql ) {
        $clean = str_replace( '\\', '', $sql );
        if ( false !== stripos( $clean, 'tmw_keyword_candidates' ) && false === stripos( $clean, 'SHOW TABLES' ) ) {
            $this->candidate_touches[] = $sql;
        }
        if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
            if ( false !== stripos( $clean, 'tmw_keyword_assignments' ) ) {
                return $this->assignments_table_exists ? 'wp_tmw_keyword_assignments' : null;
            }
            if ( false !== stripos( $clean, 'tmw_keyword_candidates' ) ) {
                return $this->candidates_table_exists ? 'wp_tmw_keyword_candidates' : null;
            }
        }
        return null;
    }
    public function get_row( string $sql, string $output = 'OBJECT' ) {
        if ( false !== stripos( $sql, 'SHOW TABLE STATUS LIKE' ) && $this->assignments_table_exists ) {
            return [ 'Engine' => $this->assignments_engine ];
        }
        return null;
    }
    public function get_results( string $sql, string $output = 'OBJECT' ): array { return []; }
    public function get_col( string $sql ): array { return []; }
    public function query( string $sql ) {
        if ( false !== stripos( $sql, 'ALTER TABLE `wp_tmw_keyword_assignments` ENGINE=InnoDB' ) ) {
            if ( $this->fail_engine_conversion ) { $this->last_error = 'conversion failed'; return false; }
            $this->assignments_engine = 'InnoDB';
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

// dbDelta stub: the schema guard checks function_exists('dbDelta') before
// requiring wp-admin/includes/upgrade.php, so defining it here lets the guard
// run in the unit environment. Records call count and flips table existence
// to simulate a successful CREATE.
$GLOBALS['_tmw_assignment_dbdelta_calls'] = 0;
if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( $queries = '', $execute = true ) {
        $GLOBALS['_tmw_assignment_dbdelta_calls']++;
        if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof AssignmentSchemaGuardWpdb ) {
            $GLOBALS['wpdb']->assignments_table_exists = true;
        }
        return [];
    }
}
