<?php
/**
 * PR-H v1.0.4 frontend-path and durable-decision guards.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';

final class RecoveryDeltaV104Test extends TestCase {
    private static string $plugin;
    private static string $cli;
    private static string $schema;

    public static function setUpBeforeClass(): void {
        self::$plugin = (string) file_get_contents( __DIR__ . '/../includes/class-plugin.php' );
        self::$cli = (string) file_get_contents( __DIR__ . '/../includes/cli/class-cli.php' );
        self::$schema = (string) file_get_contents( __DIR__ . '/../includes/db/class-schema.php' );
    }

    protected function setUp(): void {
        set_error_handler( static function ( int $number, string $message, string $file = '', int $line = 0 ): bool {
            if ( 0 === ( error_reporting() & $number ) ) { return false; }
            throw new \ErrorException( $message, 0, $number, $file, $line );
        } );
    }

    protected function tearDown(): void {
        restore_error_handler();
    }

    public function test_frontend_init_has_no_recovery_schema_metadata_path(): void {
        $this->assertStringNotContainsString(
            "        Schema::upgrade_unresolved_transaction_outcome_schema(); // PR-H",
            self::$plugin
        );
        $this->assertStringContainsString(
            "} elseif (defined('WP_CLI') && WP_CLI) {",
            self::$plugin
        );
        $this->assertSame(
            2,
            substr_count( self::$plugin, 'upgrade_unresolved_transaction_outcome_schema' ),
            'the only init references must be the admin hook and the guarded WP-CLI call'
        );
    }

    public function test_admin_init_registers_recovery_schema_upgrade(): void {
        $this->assertStringContainsString(
            "add_action('admin_init', [Schema::class, 'upgrade_unresolved_transaction_outcome_schema']);",
            self::$plugin
        );
    }

    public function test_wp_cli_verify_performs_live_repository_verification(): void {
        $this->assertStringContainsString( '$result = $repo->verify_schema();', self::$cli );
        $this->assertStringContainsString( 'independent connection and recovery schema verified', self::$cli );
    }

    public function test_activation_installs_and_verifies_recovery_schema(): void {
        $this->assertStringContainsString( 'public static function activate(): void', self::$plugin );
        $this->assertStringContainsString( 'Schema::ensure_unresolved_transaction_outcome_schema();', self::$plugin );
    }

    public function test_schema_contract_requires_resolution_decision(): void {
        $this->assertContains( 'resolution_decision', Repo::REQUIRED_COLUMNS );
        $this->assertStringContainsString( "resolution_decision VARCHAR(20) NOT NULL DEFAULT ''", self::$schema );
        $this->assertStringContainsString( "public const RECOVERY_SCHEMA_VERSION = '1.0.1'", self::$schema );
    }
}
