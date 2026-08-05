<?php
/**
 * PR-H test support — a FILE-BACKED wpdb double for the recovery subsystem.
 *
 * Durability is the whole point of PR-H, so an in-memory double would prove
 * nothing: this one persists to a JSON file, which means a marker written by one
 * PHP process is genuinely visible to a different PHP process. The
 * process-restart tests exercise that for real via subprocesses.
 *
 * It models only what the recovery repository touches:
 *   - the recovery table with UNIQUE KEY on operation_key
 *   - atomic INSERT IGNORE / conditional UPDATE / generation-gated DELETE-or-UPDATE
 *   - SHOW TABLE STATUS (engine), SHOW COLUMNS, SHOW INDEX
 *   - session variable statements
 *
 * @package TMWSEO\Engine\Recovery\Tests
 */

declare(strict_types=1);

/** Durable store shared by every connection pointed at the same path. */
final class RecoveryStore {
    public string $path;

    public function __construct( string $path ) {
        $this->path = $path;
        if ( ! file_exists( $path ) ) { $this->write( [ 'rows' => [], 'next_id' => 1 ] ); }
    }

    /** @return array<string,mixed> */
    public function read(): array {
        $raw = @file_get_contents( $this->path );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $data ) ? $data : [ 'rows' => [], 'next_id' => 1 ];
    }

    /** @param array<string,mixed> $data */
    public function write( array $data ): void {
        file_put_contents( $this->path, (string) json_encode( $data ), LOCK_EX );
    }

    public static function fresh( string $label = 'default' ): self {
        $path = sys_get_temp_dir() . '/prh-recovery-' . $label . '-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '.json';
        @unlink( $path );
        return new self( $path );
    }
}

/** A wpdb-like independent connection over a RecoveryStore. */
final class RecoveryFakeDb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $last_errno = 0;
    public int $insert_id = 0;

    private RecoveryStore $store;

    // Fault injection.
    public bool $table_missing = false;
    public string $engine = 'InnoDB';
    /** @var array<int,string> columns to hide from SHOW COLUMNS */
    public array $missing_columns = [];
    /** @var array<int,string> indexes to hide from SHOW INDEX */
    public array $missing_indexes = [];
    public bool $fail_writes = false;
    public bool $lock_timeout = false;
    public bool $fail_reads = false;
    /** Post-write verification read returns nothing even though the write landed. */
    public bool $verification_read_blind = false;
    public bool $closed = false;
    /** SET SESSION statements matching this needle fail. */
    public string $fail_session_statement = '';
    /** operation_identity reported as a non-unique index. */
    public bool $identity_index_non_unique = false;
    /** operation_identity reported as covering the wrong column. */
    public string $identity_index_column = 'operation_key';
    /** Prefix length reported for operation_identity (e.g. operation_key(100)). */
    public ?int $identity_index_sub_part = null;
    /** Sequence position reported for the operation_identity part. */
    public int $identity_index_seq = 1;
    /** Extra index parts, so multi-column and duplicate-row shapes can be modelled. */
    public array $identity_extra_parts = [];
    /**
     * Post-write read returns this row instead of the stored one, modelling a
     * competing writer that landed between the conditional UPDATE and the
     * verification read. Applied only AFTER an UPDATE has executed, so the
     * pre-check read still sees real state.
     */
    public ?array $post_write_row_override = null;
    private bool $update_executed = false;
    /** Statements executed, for lifecycle assertions. @var array<int,string> */
    public array $statements = [];

    public const COLUMNS = [
        'id', 'operation_key', 'operation_type', 'row_id', 'batch_id',
        'expected_candidate_id', 'expected_assignment_key', 'correlation_id',
        'state', 'reason', 'evidence', 'generation',
        'created_at', 'updated_at', 'resolved_at', 'resolved_by', 'resolution_reason',
    ];
    public const INDEXES = [ 'PRIMARY', 'operation_identity', 'state_row' ];

    public function __construct( RecoveryStore $store ) {
        $this->store = $store;
    }

    public function store(): RecoveryStore { return $this->store; }

    public function prepare( string $sql, ...$args ): string {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $i = 0;
        return (string) preg_replace_callback( '/%[sdf]/', static function () use ( $args, &$i ) {
            $v = $args[ $i++ ] ?? '';
            return is_string( $v ) ? "'" . addslashes( $v ) . "'" : (string) $v;
        }, $sql );
    }

    public function esc_like( string $t ): string { return addcslashes( $t, '_%\\' ); }
    public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }

    public function close(): bool { $this->closed = true; return true; }

    private function reset(): void { $this->last_error = ''; $this->last_errno = 0; }

    private function guard_write(): bool {
        if ( $this->lock_timeout ) {
            $this->last_error = 'Lock wait timeout exceeded; try restarting transaction';
            $this->last_errno = 1205;
            return false;
        }
        if ( $this->fail_writes ) {
            $this->last_error = 'Got error 1 from storage engine';
            $this->last_errno = 1030;
            return false;
        }
        if ( $this->table_missing ) {
            $this->last_error = "Table 'wp_tmw_unresolved_transaction_outcomes' doesn't exist";
            $this->last_errno = 1146;
            return false;
        }
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(): array {
        $d = $this->store->read();
        return is_array( $d['rows'] ?? null ) ? $d['rows'] : [];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function save( array $rows, ?int $next_id = null ): void {
        $d = $this->store->read();
        $d['rows'] = $rows;
        if ( null !== $next_id ) { $d['next_id'] = $next_id; }
        $this->store->write( $d );
    }

    private function find_index( string $operation_key ): ?int {
        foreach ( $this->rows() as $i => $row ) {
            if ( (string) ( $row['operation_key'] ?? '' ) === $operation_key ) { return (int) $i; }
        }
        return null;
    }

    public function query( string $sql ) {
        $this->reset();
        $this->statements[] = $sql;

        if ( preg_match( '/^SET SESSION/i', $sql ) ) {
            if ( '' !== $this->fail_session_statement && false !== stripos( $sql, $this->fail_session_statement ) ) {
                $this->last_error = 'Access denied; you need SUPER privilege for this operation';
                $this->last_errno = 1227;
                return false;
            }
            return 0;
        }

        if ( preg_match( '/^INSERT IGNORE INTO/i', $sql ) ) {
            if ( ! $this->guard_write() ) { return false; }
            if ( ! preg_match( '/\(([^)]+)\)\s*VALUES\s*\((.*)\)\s*$/is', $sql, $m ) ) { return false; }
            $cols = array_map( static fn( string $c ): string => trim( $c, " `" ), explode( ',', $m[1] ) );
            $vals = [];
            foreach ( preg_split( "/,(?=(?:[^']*'[^']*')*[^']*$)/", $m[2] ) as $v ) { $vals[] = self::literal( $v ); }
            $data = array_combine( $cols, $vals );
            if ( ! is_array( $data ) ) { return false; }
            if ( null !== $this->find_index( (string) $data['operation_key'] ) ) { return 0; }
            $d = $this->store->read();
            $id = (int) ( $d['next_id'] ?? 1 );
            $data['id'] = $id;
            $rows = $this->rows();
            $rows[] = $data;
            $this->save( $rows, $id + 1 );
            $this->insert_id = $id;
            return 1;
        }

        if ( preg_match( '/^UPDATE/i', $sql ) ) {
            if ( ! $this->guard_write() ) { return false; }
            if ( ! preg_match( '/SET\s+(.*?)\s+WHERE\s+(.*)$/is', $sql, $m ) ) { return false; }
            $where = $m[2];
            if ( ! preg_match( "/operation_key\s*=\s*'([^']*)'/i", $where, $wk ) ) { return false; }
            $idx = $this->find_index( $wk[1] );
            if ( null === $idx ) { return 0; }
            $rows = $this->rows();
            $row = $rows[ $idx ];
            if ( preg_match( '/AND\s+generation\s*=\s*(\d+)/i', $where, $wg )
                && (int) $wg[1] !== (int) ( $row['generation'] ?? 0 ) ) {
                return 0;
            }
            if ( preg_match( "/AND\s+state\s*=\s*'([^']*)'/i", $where, $ws )
                && $ws[1] !== (string) ( $row['state'] ?? '' ) ) {
                return 0;
            }
            foreach ( preg_split( '/,(?=\s*`?\w+`?\s*=)/', $m[1] ) as $pair ) {
                if ( ! preg_match( '/\s*`?(\w+)`?\s*=\s*(.*)\s*$/s', $pair, $pm ) ) { continue; }
                $raw = trim( $pm[2] );
                if ( preg_match( '/^generation\s*\+\s*1$/i', $raw ) ) {
                    $row['generation'] = (int) ( $row['generation'] ?? 0 ) + 1;
                    continue;
                }
                $row[ $pm[1] ] = self::literal( $raw );
            }
            $rows[ $idx ] = $row;
            $this->save( $rows );
            $this->update_executed = true;
            return 1;
        }

        return 0;
    }

    public function get_row( string $sql, $output = null ) {
        $this->reset();
        $this->statements[] = $sql;

        if ( preg_match( '/SHOW TABLE STATUS/i', $sql ) ) {
            if ( $this->table_missing ) { return null; }
            if ( $this->fail_reads ) { $this->last_error = 'read failure'; $this->last_errno = 2013; return null; }
            return [ 'Name' => $this->prefix . 'tmw_unresolved_transaction_outcomes', 'Engine' => $this->engine ];
        }

        if ( preg_match( "/WHERE operation_key = '([^']*)'/i", $sql, $m ) ) {
            if ( $this->fail_reads ) { $this->last_error = 'read failure'; $this->last_errno = 2013; return null; }
            if ( $this->table_missing ) { $this->last_error = "Table doesn't exist"; $this->last_errno = 1146; return null; }
            if ( $this->verification_read_blind ) { return null; }
            if ( null !== $this->post_write_row_override && $this->update_executed ) {
                $row = $this->post_write_row_override;
                $this->post_write_row_override = null;
                return $row;
            }
            $idx = $this->find_index( $m[1] );
            return null === $idx ? null : $this->rows()[ $idx ];
        }
        return null;
    }

    public function get_results( string $sql, $output = null ) {
        $this->reset();
        $this->statements[] = $sql;

        if ( preg_match( '/SHOW COLUMNS/i', $sql ) ) {
            if ( $this->table_missing ) { $this->last_error = "Table doesn't exist"; $this->last_errno = 1146; return null; }
            if ( $this->fail_reads ) { $this->last_error = 'read failure'; $this->last_errno = 2013; return null; }
            $out = [];
            foreach ( self::COLUMNS as $c ) {
                if ( in_array( $c, $this->missing_columns, true ) ) { continue; }
                $out[] = [ 'Field' => $c ];
            }
            return $out;
        }
        if ( preg_match( '/SHOW INDEX/i', $sql ) ) {
            if ( $this->table_missing ) { $this->last_error = "Table doesn't exist"; $this->last_errno = 1146; return null; }
            if ( $this->fail_reads ) { $this->last_error = 'read failure'; $this->last_errno = 2013; return null; }
            $out = [];
            foreach ( self::INDEXES as $k ) {
                if ( in_array( $k, $this->missing_indexes, true ) ) { continue; }
                $unique = ( 'operation_identity' === $k || 'PRIMARY' === $k );
                if ( 'operation_identity' === $k && $this->identity_index_non_unique ) { $unique = false; }
                $column = 'PRIMARY' === $k ? 'id' : ( 'operation_identity' === $k ? $this->identity_index_column : 'state' );
                if ( 'operation_identity' === $k ) {
                    $out[] = [
                        'Key_name'     => $k,
                        'Non_unique'   => $unique ? '0' : '1',
                        'Column_name'  => $column,
                        'Seq_in_index' => (string) $this->identity_index_seq,
                        'Sub_part'     => null === $this->identity_index_sub_part ? null : (string) $this->identity_index_sub_part,
                    ];
                    foreach ( $this->identity_extra_parts as $extra ) {
                        $out[] = array_merge( [ 'Key_name' => $k, 'Non_unique' => $unique ? '0' : '1', 'Sub_part' => null ], $extra );
                    }
                    continue;
                }
                $out[] = [ 'Key_name' => $k, 'Non_unique' => $unique ? '0' : '1', 'Column_name' => $column, 'Seq_in_index' => '1', 'Sub_part' => null ];
            }
            return $out;
        }
        if ( preg_match( "/WHERE state = '([^']*)'/i", $sql, $m ) ) {
            if ( $this->fail_reads ) { $this->last_error = 'read failure'; $this->last_errno = 2013; return null; }
            if ( $this->table_missing ) { $this->last_error = "Table doesn't exist"; $this->last_errno = 1146; return null; }
            $out = [];
            foreach ( $this->rows() as $row ) {
                if ( (string) ( $row['state'] ?? '' ) === $m[1] ) { $out[] = $row; }
            }
            return $out;
        }
        return [];
    }

    /** @return mixed */
    private static function literal( string $raw ) {
        $raw = trim( $raw );
        if ( 'NULL' === strtoupper( $raw ) ) { return null; }
        if ( preg_match( "/^'(.*)'$/s", $raw, $m ) ) { return stripslashes( $m[1] ); }
        if ( is_numeric( $raw ) ) { return $raw + 0; }
        return $raw;
    }
}

/**
 * Test connection factory. Production uses the real independent-connection
 * factory; tests inject this so no MySQL server is required.
 */
final class RecoveryFakeConnectionFactory {
    public ?RecoveryFakeDb $db;
    public bool $cannot_connect = false;
    public bool $connect_timeout = false;
    public int $opens = 0;
    public int $closes = 0;
    /** Credentials the production factory would use — must never surface. */
    public string $secret = 'super-secret-db-password';

    public function __construct( ?RecoveryFakeDb $db = null ) { $this->db = $db; }

    /** @return array<string,mixed> */
    public function open(): array {
        $this->opens++;
        if ( $this->connect_timeout ) {
            return [ 'ok' => false, 'status' => 'connection_failure', 'db' => null, 'error' => 'recovery connection timed out' ];
        }
        if ( $this->cannot_connect || null === $this->db ) {
            return [ 'ok' => false, 'status' => 'connection_failure', 'db' => null, 'error' => 'recovery connection could not be established' ];
        }
        $this->db->closed = false;
        // Uphold the production contract: a factory returns only policy-ready
        // connections. The repository does not re-apply the policy.
        $policy = \TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeConnection::apply_session_policy( $this->db );
        if ( empty( $policy['ok'] ) ) {
            $this->db->close();
            return [ 'ok' => false, 'status' => 'connection_policy_failure', 'db' => null, 'error' => (string) $policy['error'] ];
        }
        return [ 'ok' => true, 'status' => 'ok', 'db' => $this->db, 'error' => '' ];
    }

    public function close( $db ): void {
        $this->closes++;
        if ( $db instanceof RecoveryFakeDb ) { $db->close(); }
    }
}
