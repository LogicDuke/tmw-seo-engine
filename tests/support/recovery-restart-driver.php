<?php
/**
 * PR-H test support — separate-process durability probe.
 *
 * Runs in its OWN PHP process with its OWN connection object over the durable
 * recovery store, which is what makes the process-restart test meaningful: no
 * state is inherited from the recording process.
 *
 * Usage: php tests/support/recovery-restart-driver.php <store-path> <operation-key>
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/wordpress-stubs.php';
require_once __DIR__ . '/RecoveryFakeDb.php';
require_once __DIR__ . '/../../includes/recovery/class-unresolved-transaction-outcome-connection.php';
require_once __DIR__ . '/../../includes/recovery/class-unresolved-transaction-outcome-repository.php';

use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

$path = (string) ( $argv[1] ?? '' );
$key  = (string) ( $argv[2] ?? '' );

$db      = new RecoveryFakeDb( new RecoveryStore( $path ) );
$factory = new RecoveryFakeConnectionFactory( $db );
$repo    = new Repo( $factory );

$found    = $repo->find_unresolved_outcome( $key );
$blocking = $repo->has_blocking_outcome( [ 'operation_key' => $key ] );

echo "\n__RESTART_PROBE__" . json_encode( [
    'found'    => ! empty( $found['found'] ),
    'blocking' => ! empty( $blocking['blocking'] ),
    'row_id'   => (int) ( $found['row']['row_id'] ?? 0 ),
    'state'    => (string) ( $found['row']['state'] ?? '' ),
] ) . "__END__\n";
