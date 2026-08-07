<?php
/**
 * TMW SEO Engine — Independent recovery database connection (PR-H).
 *
 * The recovery subsystem exists precisely for the moments when the primary
 * WordPress `$wpdb` connection cannot be trusted: still inside a transaction,
 * in an unknown transaction state, disconnected, or silently reconnected. A
 * marker written over that connection is not durable, because it shares the
 * fate of whatever transaction is open on it.
 *
 * This factory therefore opens a SEPARATE, short-lived `wpdb` instance using the
 * database constants WordPress has already defined at runtime. It is the only
 * place in the plugin that does so, and it is used only by the recovery
 * subsystem.
 *
 * Safety properties:
 *  - credentials are read from constants and NEVER logged, returned, or
 *    interpolated into an error string;
 *  - the connection uses a short session lock-wait timeout, so a recovery write
 *    that contends with a stuck transaction fails fast instead of hanging a
 *    request that is already in an unknown state;
 *  - the connection is closed after every operation — it is never cached, and
 *    never joins the caller's transaction;
 *  - connection failure is reported distinctly from schema and write failure;
 *  - everything fails closed.
 *
 * @package TMWSEO\Engine\Recovery
 * @since   5.9.29-recovery-outcomes-v1.0.5
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Recovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-recovery-native-mysqli-connect-trait.php';

class UnresolvedTransactionOutcomeConnection {

    use RecoveryNativeMysqliConnectTrait;

    /** Seconds a recovery statement may wait for a row lock before giving up. */
    public const LOCK_WAIT_TIMEOUT = 3;

    /** Seconds to wait for the TCP/socket connection itself. */
    public const CONNECT_TIMEOUT = 3;

    /** Session statements that MUST apply before any recovery read or write. */
    public const SESSION_POLICY = [
        'SET SESSION innodb_lock_wait_timeout = 3',
        'SET SESSION lock_wait_timeout = 3',
    ];

    public const LOG_TAG = '[TMW-RECOVERY]';

    /**
     * Open an independent connection.
     *
     * @return array{ok:bool,status:string,db:mixed,error:string}
     */
    public function open(): array {
        foreach ( [ 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ] as $constant ) {
            if ( ! defined( $constant ) ) {
                return $this->failure( 'recovery connection unavailable: ' . $constant . ' is not defined' );
            }
        }

        $db             = null;
        $connected      = false;
        $connection_err = false;
        try {
            // create_wpdb() deliberately constructs an UNCONNECTED wpdb
            // subclass. The real connection is made explicitly with
            // db_connect(false), so WordPress cannot bail()/wp_die() when the
            // independent recovery connection is unavailable.
            $db = $this->create_wpdb();
            $db->suppress_errors( true );
            $db->hide_errors();
            $connected = $this->connect_wpdb( $db ) && ! empty( $db->dbh );
        } catch ( \Throwable $e ) {
            // Driver/constructor messages can echo connection details, so they
            // are never propagated.
            $connection_err = true;
        }

        if ( $connection_err || ! $connected ) {
            $this->log( 'independent connection did not come up' );
            $this->close( $db );
            return $this->failure( 'recovery connection could not be established' );
        }

        global $wpdb;
        if ( ! is_object( $wpdb )
            || ! isset( $wpdb->base_prefix, $wpdb->prefix, $wpdb->blogid )
            || ! method_exists( $db, 'set_prefix' )
            || ! method_exists( $db, 'set_blog_id' )
        ) {
            $this->close( $db );
            return [
                'ok' => false, 'status' => 'connection_policy_failure', 'db' => null,
                'error' => 'recovery blog context could not be applied',
            ];
        }

        $db->set_prefix( (string) $wpdb->base_prefix );
        $db->set_blog_id( (int) $wpdb->blogid );

        if ( (string) $db->prefix !== (string) $wpdb->prefix ) {
            $this->close( $db );
            return [
                'ok' => false, 'status' => 'connection_policy_failure', 'db' => null,
                'error' => 'recovery blog context could not be applied',
            ];
        }

        $policy = self::apply_session_policy( $db );
        if ( empty( $policy['ok'] ) ) {
            $this->close( $db );
            return [
                'ok' => false, 'status' => 'connection_policy_failure', 'db' => null,
                'error' => (string) $policy['error'],
            ];
        }

        return [ 'ok' => true, 'status' => 'ok', 'db' => $db, 'error' => '' ];
    }

    /**
     * Construct the independent connection.
     *
     * A fresh instance — never the global $wpdb. Isolated behind a seam so the
     * production path can be exercised without a live server; production
     * behaviour is unchanged.
     *
     * @return mixed
     */
    protected function create_wpdb() {
        if ( ! class_exists( '\\wpdb' ) ) {
            throw new \RuntimeException( 'wpdb class is not loaded' );
        }

        // Run the parent constructor so WordPress initializes its private
        // database-driver state, but intercept the constructor's automatic
        // connection attempt. The real connection is made explicitly below
        // with allow_bail=false.
        return new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends \wpdb {
            private bool $tmwseo_defer_connect = true;

            public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {
                parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );

                $this->tmwseo_defer_connect = false;
                $this->reconnect_retries    = 0;
            }

            public function db_connect( $allow_bail = true ) {
                if ( $this->tmwseo_defer_connect ) {
                    return true;
                }

                return parent::db_connect( false );
            }


            public function tmwseo_connect_with_native_timeout( callable $connector ): bool {
                $this->is_mysql = true;

                $host    = $this->dbhost;
                $port    = null;
                $socket  = null;
                $is_ipv6 = false;

                $parsed = $this->parse_db_host( $this->dbhost );
                if ( $parsed ) {
                    [ $host, $port, $socket, $is_ipv6 ] = $parsed;
                }

                if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
                    $host = '[' . $host . ']';
                }

                $flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0;

                $dbh = $connector(
                    (string) $host,
                    (string) $this->dbuser,
                    (string) $this->dbpassword,
                    null,
                    null === $port ? null : (int) $port,
                    null === $socket ? null : (string) $socket,
                    $flags
                );

                if ( false === $dbh || null === $dbh ) {
                    $this->dbh   = null;
                    $this->ready = false;
                    return false;
                }

                $this->dbh = $dbh;
                $this->init_charset();
                $this->set_charset( $this->dbh );
                $this->ready = true;

                if ( property_exists( $this, 'has_connected' ) ) {
                    $this->has_connected = true;
                }

                $this->set_sql_mode();
                $this->select( $this->dbname, $this->dbh );

                return true;
            }

            /**
             * Return the active driver errno without exposing the handle.
             *
             * Null means no live mysqli handle was available, allowing injected
             * test doubles to supply their existing last_errno fallback.
             */
            public function tmwseo_driver_errno(): ?int {
                if ( ! is_object( $this->dbh ) || ! ( $this->dbh instanceof \mysqli ) ) {
                    return null;
                }

                try {
                    return \mysqli_errno( $this->dbh );
                } catch ( \Throwable $e ) {
                    return null;
                }
            }

            public function check_connection( $allow_bail = true ) {
                // Fail closed. A replacement handle would not have the
                // recovery timeout policies re-established and verified.
                return false;
            }
        };
    }

    /** Connect an unconnected recovery wpdb without allowing WordPress to bail. */
    protected function connect_wpdb( $db ): bool {
        if ( ! is_object( $db ) ) {
            return false;
        }

        if ( method_exists( $db, 'tmwseo_connect_with_native_timeout' ) ) {
            return true === $db->tmwseo_connect_with_native_timeout(
                fn(
                    string $host,
                    string $user,
                    string $password,
                    ?string $database,
                    ?int $port,
                    ?string $socket,
                    int $flags
                ) => $this->recovery_native_mysqli_connect(
                    $host,
                    $user,
                    $password,
                    $database,
                    $port,
                    $socket,
                    $flags,
                    self::CONNECT_TIMEOUT
                )
            );
        }

        // Test doubles may arrive already connected.
        return ! empty( $db->dbh );
    }

    /**
     * Apply every session policy statement and confirm each one took effect.
     *
     * @return array{ok:bool,error:string}
     */
    public static function apply_session_policy( $db ): array {
        foreach ( self::SESSION_POLICY as $statement ) {
            $db->last_error = '';
            $result = $db->query( $statement );
            if ( false === $result || '' !== (string) $db->last_error ) {
                // The driver message may name the user, so it is not returned.
                error_log( self::LOG_TAG . ' session policy statement was refused: ' . $statement );
                return [ 'ok' => false, 'error' => 'recovery session timeout policy could not be applied' ];
            }
        }
        return [ 'ok' => true, 'error' => '' ];
    }

    /** Release the connection. It is never reused or cached. */
    public function close( $db ): void {
        if ( is_object( $db ) && method_exists( $db, 'close' ) ) {
            try { $db->close(); } catch ( \Throwable $e ) { /* releasing must never throw */ }
        }
    }

    /** @return array{ok:bool,status:string,db:null,error:string} */
    private function failure( string $error ): array {
        return [ 'ok' => false, 'status' => 'connection_failure', 'db' => null, 'error' => $error ];
    }

    protected function log( string $message ): void {
        // Never interpolate DB_USER / DB_PASSWORD / DB_HOST / DB_NAME here.
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
