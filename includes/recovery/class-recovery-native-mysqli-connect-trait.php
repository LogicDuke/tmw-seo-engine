<?php
/**
 * Native bounded MySQLi connection helpers for PR-H recovery.
 *
 * @package TMWSEO\Engine\Recovery
 * @since   5.9.29-recovery-outcomes-v1.0.6
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Recovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait RecoveryNativeMysqliConnectTrait {

    /**
     * Open a native MySQLi handle with a bounded connection timeout.
     *
     * The timeout is applied directly to the newly initialized driver handle,
     * before any network connection is attempted.
     *
     * @return mixed Connected mysqli handle on success, false on failure.
     */
    protected function recovery_native_mysqli_connect(
        string $host,
        string $user,
        string $password,
        ?string $database,
        ?int $port,
        ?string $socket,
        int $flags,
        int $connect_timeout
    ) {
        if ( ! defined( 'MYSQLI_OPT_CONNECT_TIMEOUT' ) ) {
            return false;
        }

        if ( ! $this->recovery_mysqli_disable_reporting() ) {
            return false;
        }

        $dbh = $this->recovery_mysqli_init();
        if ( ! $dbh ) {
            return false;
        }

        if ( ! $this->recovery_mysqli_options( $dbh, MYSQLI_OPT_CONNECT_TIMEOUT, $connect_timeout ) ) {
            return false;
        }

        if ( ! $this->recovery_mysqli_real_connect(
            $dbh,
            $host,
            $user,
            $password,
            $database,
            $port,
            $socket,
            $flags
        ) ) {
            return false;
        }

        return $dbh;
    }

    protected function recovery_mysqli_disable_reporting(): bool {
        if ( ! function_exists( 'mysqli_report' ) || ! defined( 'MYSQLI_REPORT_OFF' ) ) {
            return false;
        }

        \mysqli_report( MYSQLI_REPORT_OFF );
        return true;
    }

    protected function recovery_mysqli_init() {
        if ( ! function_exists( 'mysqli_init' ) ) {
            return false;
        }

        return \mysqli_init();
    }

    protected function recovery_mysqli_options( $dbh, int $option, int $value ): bool {
        if ( ! function_exists( 'mysqli_options' ) ) {
            return false;
        }

        return \mysqli_options( $dbh, $option, $value );
    }

    protected function recovery_mysqli_real_connect(
        $dbh,
        string $host,
        string $user,
        string $password,
        ?string $database,
        ?int $port,
        ?string $socket,
        int $flags
    ): bool {
        if ( ! function_exists( 'mysqli_real_connect' ) ) {
            return false;
        }

        return @\mysqli_real_connect(
            $dbh,
            $host,
            $user,
            $password,
            $database,
            $port,
            $socket,
            $flags
        );
    }
}