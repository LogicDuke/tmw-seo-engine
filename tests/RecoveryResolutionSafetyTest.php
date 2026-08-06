<?php
/**
 * Resolution messaging and database error disclosure regressions.
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\CLI\TMWSEOCommand;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

if ( ! defined( 'WP_CLI' ) ) { define( 'WP_CLI', true ); }
if ( ! class_exists( 'WP_CLI_Command' ) ) { class WP_CLI_Command {} }
if ( ! class_exists( 'WP_CLI' ) ) {
    class WP_CLI {
        public static function add_command( string $name, string $class ): void {}
    }
}

require_once __DIR__ . '/support/RecoveryFakeDb.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';
require_once __DIR__ . '/../includes/cli/class-cli.php';

final class RecoveryResolutionSafetyTest extends TestCase {
    private RecoveryStore $store;
    private RecoveryFakeDb $db;
    private Repo $repo;

    protected function setUp(): void {
        set_error_handler( static function ( int $number, string $message, string $file = '', int $line = 0 ): bool {
            if ( 0 === ( error_reporting() & $number ) ) { return false; }
            throw new \ErrorException( $message, 0, $number, $file, $line );
        } );
        $this->store = RecoveryStore::fresh( 'resolution-safety' );
        $this->db = new RecoveryFakeDb( $this->store );
        $this->repo = new Repo( new RecoveryFakeConnectionFactory( $this->db ) );
    }

    protected function tearDown(): void {
        restore_error_handler();
        @unlink( $this->store->path );
    }

    /** @return array<string,mixed> */
    private function outcome(): array {
        return [
            'operation_key' => 'manual_approval:row:900', 'operation_type' => 'manual_approval',
            'row_id' => 900, 'batch_id' => 70, 'reason' => 'rollback_failed', 'evidence' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function decision(): array {
        return [
            'decision' => 'acknowledged', 'resolved_by' => 42,
            'resolution_reason' => 'verified against committed state', 'evidence' => [],
        ];
    }

    /** @param array<string,mixed> $result */
    private function message( array $result ): string {
        $method = new ReflectionMethod( TMWSEOCommand::class, 'resolution_failure_message' );
        return (string) $method->invoke( null, $result, 'manual_approval:row:900' );
    }

    public function test_definite_pre_write_refusal_says_nothing_changed(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $result = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'] + 1, $this->decision() );
        $message = $this->message( $result );

        $this->assertSame( 'stale_generation', $result['status'] );
        $this->assertStringContainsString( 'REFUSED', $message );
        $this->assertStringContainsString( 'nothing was changed', $message );
    }

    public function test_successful_update_with_failed_verification_read_is_indeterminate(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $this->db->verification_read_failure = true;
        $result = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $this->decision() );
        $message = $this->message( $result );

        $this->assertSame( 'verification_failure', $result['status'] );
        $this->assertStringContainsString( 'INDETERMINATE', $message );
        $this->assertStringContainsString( 'Inspect marker', $message );
        $this->assertStringContainsString( 'reopen', $message );
        $this->assertStringNotContainsString( 'nothing was changed', $message );
        $this->assertStringNotContainsString( 'remains unresolved', $message );
    }

    public function test_successful_update_with_mismatched_verification_is_indeterminate(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $this->db->post_write_row_override = [
            'operation_key' => 'manual_approval:row:900', 'operation_type' => 'manual_approval',
            'row_id' => 900, 'batch_id' => 70, 'generation' => (int) $recorded['generation'],
            'state' => 'resolved', 'resolved_by' => 7, 'resolution_reason' => 'different',
        ];
        $result = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $this->decision() );
        $message = $this->message( $result );

        $this->assertSame( 'verification_failure', $result['status'] );
        $this->assertStringContainsString( 'INDETERMINATE', $message );
        $this->assertStringNotContainsString( 'nothing was changed', $message );
    }

    public function test_normal_verified_resolution_success_is_unchanged(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $result = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $this->decision() );

        $this->assertTrue( $result['ok'], (string) $result['status'] );
        $this->assertSame( 'ok', $result['status'] );
        $this->assertSame( 'resolved', $result['row']['state'] );
        $this->assertSame( 'acknowledged', $result['row']['resolution_decision'] );
    }

    public function test_discarded_decision_is_persisted_and_verified(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $decision = $this->decision();
        $decision['decision'] = 'discarded';

        $result = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $decision );

        $this->assertTrue( $result['ok'], (string) $result['status'] );
        $this->assertSame( 'discarded', $result['row']['resolution_decision'] );
    }

    public function test_mismatched_post_write_decision_is_indeterminate(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $this->db->post_write_row_override = [
            'operation_key' => 'manual_approval:row:900', 'operation_type' => 'manual_approval',
            'row_id' => 900, 'batch_id' => 70, 'generation' => (int) $recorded['generation'],
            'state' => 'resolved', 'resolved_by' => 42,
            'resolution_reason' => 'verified against committed state',
            'resolution_decision' => 'discarded',
        ];

        $result = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $this->decision() );

        $this->assertSame( 'verification_failure', $result['status'] );
        $this->assertStringContainsString( 'INDETERMINATE', $this->message( $result ) );
    }

    public function test_stale_generation_does_not_overwrite_persisted_decision(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $resolved = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $this->decision() );
        $decision = $this->decision();
        $decision['decision'] = 'discarded';

        $refused = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'] + 1, $decision );
        $row = ( new RecoveryFakeDb( new RecoveryStore( $this->store->path ) ) )->get_row(
            "SELECT * FROM wp_tmw_unresolved_transaction_outcomes WHERE operation_key = 'manual_approval:row:900' LIMIT 1",
            ARRAY_A
        );

        $this->assertFalse( $refused['ok'] );
        $this->assertSame( 'acknowledged', $resolved['row']['resolution_decision'] );
        $this->assertSame( 'acknowledged', $row['resolution_decision'] );
    }

    public function test_zero_row_resolution_does_not_overwrite_persisted_decision(): void {
        $recorded = $this->repo->record_unresolved_outcome( $this->outcome() );
        $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $this->decision() );
        $decision = $this->decision();
        $decision['decision'] = 'discarded';

        $refused = $this->repo->resolve_outcome( 'manual_approval:row:900', (int) $recorded['generation'], $decision );
        $row = ( new RecoveryFakeDb( new RecoveryStore( $this->store->path ) ) )->get_row(
            "SELECT * FROM wp_tmw_unresolved_transaction_outcomes WHERE operation_key = 'manual_approval:row:900' LIMIT 1",
            ARRAY_A
        );

        $this->assertSame( 'stale_generation', $refused['status'] );
        $this->assertSame( 'acknowledged', $row['resolution_decision'] );
    }

    public function test_credential_bearing_driver_error_is_redacted_from_results_and_output(): void {
        $secret = 'password=hunter2 user=tmw_admin host=db.internal connection=mysql://tmw_admin:hunter2@db.internal';
        $this->db->fail_reads = true;
        $this->db->read_error_message = $secret;
        $result = $this->repo->find_unresolved_outcome( 'manual_approval:row:900' );
        $message = $this->message( $result );
        $serialized = json_encode( $result ) . $message;

        $this->assertSame( '', $result['db_error']['message'] );
        foreach ( [ 'hunter2', 'tmw_admin', 'db.internal', 'mysql://', $secret ] as $sensitive ) {
            $this->assertStringNotContainsString( $sensitive, $serialized );
        }
    }
}
