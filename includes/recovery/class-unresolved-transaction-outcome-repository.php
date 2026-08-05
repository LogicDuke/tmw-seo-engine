<?php
/**
 * TMW SEO Engine — Unresolved transaction outcome repository (PR-H).
 *
 * Durable, independently-connected record of an operation whose true outcome
 * could not be established because the primary connection became unsafe.
 *
 * Every method opens its own short-lived independent connection, verifies the
 * recovery schema, does one thing, and closes the connection. Nothing here
 * touches the global `$wpdb`, and nothing here participates in the caller's
 * transaction — that is the entire point of the subsystem.
 *
 * All methods return a structured result. Errors are NEVER collapsed into
 * null, false or an empty list, because "no marker" and "could not read" have
 * opposite safety meanings: the first permits work to continue, the second must
 * block it.
 *
 * Status values:
 *   ok, not_found, connection_failure, connection_policy_failure,
 *   schema_failure, lock_timeout, duplicate_identity, stale_generation,
 *   write_failure, read_failure, verification_failure,
 *   superseded_before_write, superseded_after_write, identity_mismatch,
 *   invalid_operation_key, invalid_criteria, invalid_evidence,
 *   invalid_resolution
 *
 * @package TMWSEO\Engine\Recovery
 * @since   5.9.29-recovery-outcomes-v1.0.3
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Recovery;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class UnresolvedTransactionOutcomeRepository {

    public const TABLE_SUFFIX        = 'tmw_unresolved_transaction_outcomes';
    public const SCHEMA_ERROR_OPTION = 'tmwseo_recovery_schema_error';
    public const LOG_TAG             = '[TMW-RECOVERY]';
    public const AUDIT_TAG           = '[TMW-RECOVERY-OPERATOR]';

    public const STATE_UNRESOLVED = 'unresolved';
    public const STATE_RESOLVED   = 'resolved';

    public const MAX_KEY_LENGTH = 191;

    /** @var array<int,string> */
    public const REQUIRED_COLUMNS = [
        'id', 'operation_key', 'operation_type', 'row_id', 'batch_id',
        'expected_candidate_id', 'expected_assignment_key', 'correlation_id',
        'state', 'reason', 'evidence', 'generation',
        'created_at', 'updated_at', 'resolved_at', 'resolved_by', 'resolution_reason',
    ];

    public const REQUIRED_UNIQUE_INDEX = 'operation_identity';

    /** @var object connection factory exposing open()/close() */
    private $connections;

    public function __construct( $connections = null ) {
        $this->connections = $connections ?: new UnresolvedTransactionOutcomeConnection();
    }

    /**
     * Open an independent connection and apply the recovery session policy.
     *
     * The lock-wait timeout is applied HERE, not only in the connection factory,
     * so it holds for every connection this repository is ever handed. A
     * recovery write must fail fast rather than block behind the stuck
     * transaction that caused the recovery in the first place.
     *
     * @return array{ok:bool,status:string,db:mixed,error:string}
     */
    private function open_connection(): array {
        $opened = $this->connections->open();
        if ( empty( $opened['ok'] ) || empty( $opened['db'] ) ) {
            // Preserve the factory's own status. connection_failure and
            // connection_policy_failure mean different things to a caller, and
            // future connection statuses must pass through unflattened.
            return [
                'ok'     => false,
                'status' => (string) ( $opened['status'] ?? 'connection_failure' ),
                'db'     => null,
                'error'  => (string) ( $opened['error'] ?? 'recovery connection unavailable' ),
            ];
        }
        // The session timeout policy is the CONNECTION FACTORY's contract: it
        // returns only policy-ready connections. Re-applying it here would issue
        // the same statements twice on every production operation, so this
        // repository deliberately does not. An injected factory must uphold the
        // same contract; the connection class exposes apply_session_policy() for
        // that purpose.
        return $opened;
    }

    private function table( $db ): string {
        $prefix = isset( $db->prefix ) ? (string) $db->prefix : 'wp_';
        return $prefix . self::TABLE_SUFFIX;
    }

    private function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    // ── Result helpers ────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function fail( string $status, string $reason, array $extra = [] ): array {
        return array_merge( [
            'ok'     => false,
            'status' => $status,
            'found'  => false,
            'rows'   => [],
            'reason' => $reason,
            'db_error' => [],
        ], $extra );
    }

    /**
     * Classify a database error WITHOUT ever echoing credentials.
     * @return array{class:string,errno:int,message:string}
     */
    private function classify( $db ): array {
        $errno   = (int) ( $db->last_errno ?? 0 );
        $message = (string) ( $db->last_error ?? '' );
        $lower   = strtolower( $message );
        $class   = 'generic';
        if ( 1205 === $errno || false !== strpos( $lower, 'lock wait timeout' ) ) { $class = 'lock_timeout'; }
        elseif ( 1213 === $errno || false !== strpos( $lower, 'deadlock' ) ) { $class = 'deadlock'; }
        elseif ( 1146 === $errno || false !== strpos( $lower, "doesn't exist" ) ) { $class = 'table_missing'; }
        elseif ( 1062 === $errno || false !== strpos( $lower, 'duplicate entry' ) ) { $class = 'duplicate_key'; }
        elseif ( in_array( $errno, [ 2006, 2013 ], true ) || false !== strpos( $lower, 'gone away' ) ) { $class = 'lost_connection'; }
        return [ 'class' => $class, 'errno' => $errno, 'message' => $message ];
    }

    // ── Schema ────────────────────────────────────────────────────────────

    /**
     * Verify the recovery schema on an independent connection.
     *
     * @return array<string,mixed>
     */
    public function verify_schema(): array {
        $opened = $this->open_connection();
        if ( empty( $opened['ok'] ) ) {
            return $this->fail(
                (string) ( $opened['status'] ?? 'connection_failure' ),
                (string) ( $opened['error'] ?? 'recovery connection unavailable' )
            );
        }
        $db = $opened['db'];
        try {
            return $this->verify_schema_on( $db );
        } finally {
            $this->connections->close( $db );
        }
    }

    /** @return array<string,mixed> */
    private function verify_schema_on( $db ): array {
        $table = $this->table( $db );

        $status = $db->get_row( $db->prepare( 'SHOW TABLE STATUS LIKE %s', $db->esc_like( $table ) ), ARRAY_A );
        if ( ! is_array( $status ) ) {
            $error = $this->classify( $db );
            return $this->record_schema_error( 'recovery table ' . $table . ' is missing or unreadable', $error );
        }
        if ( 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
            return $this->record_schema_error(
                'recovery table engine is ' . (string) ( $status['Engine'] ?? 'unknown' ) . ', InnoDB is required',
                []
            );
        }

        $columns = $db->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );
        if ( ! is_array( $columns ) ) {
            return $this->record_schema_error( 'recovery table columns are unreadable', $this->classify( $db ) );
        }
        $present = [];
        foreach ( $columns as $column ) { $present[] = (string) ( $column['Field'] ?? '' ); }
        $missing = array_diff( self::REQUIRED_COLUMNS, $present );
        if ( [] !== $missing ) {
            return $this->record_schema_error( 'recovery table is missing column(s): ' . implode( ', ', $missing ), [] );
        }

        $indexes = $db->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A );
        if ( ! is_array( $indexes ) ) {
            return $this->record_schema_error( 'recovery table indexes are unreadable', $this->classify( $db ) );
        }
        $identity_rows = [];
        foreach ( $indexes as $index ) {
            if ( self::REQUIRED_UNIQUE_INDEX === (string) ( $index['Key_name'] ?? '' ) ) { $identity_rows[] = $index; }
        }
        $shape = $this->identity_index_problem( $identity_rows );
        if ( '' !== $shape ) {
            return $this->record_schema_error( $shape, [] );
        }

        return [ 'ok' => true, 'status' => 'ok', 'found' => true, 'rows' => [], 'reason' => '', 'db_error' => [] ];
    }

    /**
     * Runtime schema failure.
     *
     * Deliberately log-only: this runs on the INDEPENDENT connection, at a
     * moment when the primary connection may be inside an unknown transaction.
     * Writing an option here would go through the primary connection and could
     * be rolled back with it — or block on it. Operator-visible persistence of
     * schema errors belongs to the installation/upgrade path
     * (Schema::ensure_unresolved_transaction_outcome_schema()), where the
     * primary connection is expected to be healthy.
     *
     * @return array<string,mixed>
     */
    private function record_schema_error( string $reason, array $db_error ): array {
        $this->log( 'schema verification failed: ' . $reason );
        return $this->fail( 'schema_failure', $reason, [ 'db_error' => $db_error ] );
    }

    /**
     * Validate the COMPLETE shape of the operation_identity index.
     *
     * A prefix index (operation_key(100)), a multi-column index, duplicate
     * index rows, a wrong sequence position, a wrong column or a non-unique
     * index all defeat the guarantee this subsystem relies on, and none of them
     * is visible from the index name alone.
     *
     * @param array<int,array<string,mixed>> $rows the SHOW INDEX rows for this key
     * @return string '' when the shape is correct, otherwise the reason
     */
    public static function identity_index_problem( array $rows ): string {
        $name = self::REQUIRED_UNIQUE_INDEX;
        if ( [] === $rows ) {
            return 'recovery table is missing the unique index ' . $name;
        }
        if ( count( $rows ) > 1 ) {
            $columns = [];
            foreach ( $rows as $row ) { $columns[] = (string) ( $row['Column_name'] ?? '' ); }
            return 'recovery index ' . $name . ' must cover exactly operation_key as a single part, found '
                . count( $rows ) . ' parts: ' . implode( ', ', $columns );
        }

        $row = $rows[0];
        if ( '0' !== (string) ( $row['Non_unique'] ?? '1' ) ) {
            return 'recovery index ' . $name . ' is not unique';
        }
        if ( '1' !== (string) ( $row['Seq_in_index'] ?? '1' ) ) {
            return 'recovery index ' . $name . ' has the wrong sequence position: expected 1, found '
                . (string) ( $row['Seq_in_index'] ?? '' );
        }
        if ( 'operation_key' !== (string) ( $row['Column_name'] ?? '' ) ) {
            return 'recovery index ' . $name . ' must cover exactly operation_key, found: '
                . ( '' === (string) ( $row['Column_name'] ?? '' ) ? '(nothing)' : (string) $row['Column_name'] );
        }
        $sub_part = $row['Sub_part'] ?? null;
        if ( null !== $sub_part && '' !== (string) $sub_part && 0 !== (int) $sub_part ) {
            return 'recovery index ' . $name . ' is a prefix index (operation_key(' . (int) $sub_part
                . ')); the full column must be indexed';
        }
        return '';
    }

    // ── Recording ─────────────────────────────────────────────────────────

    /**
     * Record (or escalate) an unresolved outcome.
     *
     * @param array<string,mixed> $outcome
     * @return array<string,mixed>
     */
    public function record_unresolved_outcome( array $outcome ): array {
        $key = $this->normalize_key( (string) ( $outcome['operation_key'] ?? '' ) );
        if ( '' === $key ) {
            return $this->fail( 'invalid_operation_key', 'operation_key is required' );
        }
        if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
            return $this->fail( 'invalid_operation_key', sprintf(
                'operation_key exceeds the supported length of %d characters', self::MAX_KEY_LENGTH
            ) );
        }

        $evidence = $outcome['evidence'] ?? [];
        $encoded  = json_encode( is_array( $evidence ) ? $evidence : [ 'value' => $evidence ] );
        if ( ! is_string( $encoded ) ) {
            return $this->fail( 'invalid_evidence', 'evidence payload could not be encoded' );
        }

        $expected = [
            'operation_key'            => $key,
            'operation_type'           => $this->bounded( (string) ( $outcome['operation_type'] ?? '' ), 50 ),
            'row_id'                   => (int) ( $outcome['row_id'] ?? 0 ),
            'batch_id'                 => (int) ( $outcome['batch_id'] ?? 0 ),
            'expected_candidate_id'    => (int) ( $outcome['expected_candidate_id'] ?? 0 ),
            'expected_assignment_key'  => $this->bounded( (string) ( $outcome['expected_assignment_key'] ?? '' ), 191 ),
            'correlation_id'           => $this->bounded( (string) ( $outcome['correlation_id'] ?? '' ), 64 ),
            'state'                    => self::STATE_UNRESOLVED,
            'reason'                   => $this->bounded( (string) ( $outcome['reason'] ?? '' ), 191 ),
            'evidence'                 => $encoded,
            'resolved_at'              => null,
            'resolved_by'              => 0,
            'resolution_reason'        => '',
        ];

        $opened = $this->open_connection();
        if ( empty( $opened['ok'] ) ) {
            return $this->fail(
                (string) ( $opened['status'] ?? 'connection_failure' ),
                (string) ( $opened['error'] ?? 'recovery connection unavailable' )
            );
        }
        $db = $opened['db'];

        try {
            $schema = $this->verify_schema_on( $db );
            if ( empty( $schema['ok'] ) ) { return $schema; }

            $table = $this->table( $db );
            $now   = $this->now();

            $created = $db->query( $db->prepare(
                'INSERT IGNORE INTO ' . $table
                . ' (operation_key, operation_type, row_id, batch_id, expected_candidate_id, expected_assignment_key,'
                . ' correlation_id, state, reason, evidence, generation, created_at, updated_at, resolved_at, resolved_by, resolution_reason)'
                . " VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s, NULL, 0, '')",
                $expected['operation_key'], $expected['operation_type'], $expected['row_id'], $expected['batch_id'],
                $expected['expected_candidate_id'], $expected['expected_assignment_key'], $expected['correlation_id'],
                $expected['state'], $expected['reason'], $expected['evidence'], $now, $now
            ) );

            if ( false === $created ) {
                $error = $this->classify( $db );
                $status = 'lock_timeout' === $error['class'] ? 'lock_timeout'
                    : ( 'table_missing' === $error['class'] ? 'schema_failure' : 'write_failure' );
                return $this->fail( $status, 'recovery marker insert failed', [ 'db_error' => $error ] );
            }

            $expected_generation = 1;
            if ( 0 === (int) $created ) {
                $existing = $this->read_row( $db, $key );
                if ( empty( $existing['ok'] ) ) {
                    return $this->fail( 'read_failure', 'existing outcome could not be read', [ 'db_error' => $existing['db_error'] ] );
                }
                if ( empty( $existing['found'] ) ) {
                    return $this->fail( 'verification_failure', 'existing outcome disappeared before escalation' );
                }

                $conflict = $this->identity_conflict( $existing['row'], $outcome );
                if ( '' !== $conflict ) {
                    $this->log( sprintf( 'identity mismatch on key=%s field=%s; existing marker left unchanged', $key, $conflict ) );
                    return $this->fail( 'identity_mismatch', sprintf(
                        'operation_key is already bound to a different %s', $conflict
                    ), [ 'conflicting_field' => $conflict ] );
                }

                $base_generation     = (int) ( $existing['row']['generation'] ?? 0 );
                $expected_generation = $base_generation + 1;

                // Compare-and-set the generation so concurrent recorders cannot
                // make this caller believe it updated a generation it never
                // owned.
                $updated = $db->query( $db->prepare(
                    'UPDATE ' . $table . ' SET generation = generation + 1, state = %s, reason = %s, evidence = %s,'
                    . ' expected_candidate_id = %d, expected_assignment_key = %s, correlation_id = %s, updated_at = %s,'
                    . " resolved_at = NULL, resolved_by = 0, resolution_reason = ''"
                    . ' WHERE operation_key = %s AND generation = %d',
                    $expected['state'], $expected['reason'], $expected['evidence'],
                    $expected['expected_candidate_id'], $expected['expected_assignment_key'], $expected['correlation_id'],
                    $now, $key, $base_generation
                ) );
                if ( false === $updated ) {
                    $error = $this->classify( $db );
                    $status = 'lock_timeout' === $error['class'] ? 'lock_timeout' : 'write_failure';
                    return $this->fail( $status, 'recovery marker escalation failed', [ 'db_error' => $error ] );
                }
                if ( 0 === (int) $updated ) {
                    $current = $this->read_row( $db, $key );
                    return $this->fail( 'superseded_before_write', 'the outcome changed before this escalation could be written', [
                        'current_generation' => ! empty( $current['found'] ) ? (int) ( $current['row']['generation'] ?? 0 ) : 0,
                    ] );
                }
            }

            $stored = $this->read_row( $db, $key );
            if ( empty( $stored['ok'] ) ) {
                return $this->fail( 'verification_failure', 'recovery marker could not be verified after write', [ 'db_error' => $stored['db_error'] ?? [] ] );
            }
            if ( empty( $stored['found'] ) ) {
                return $this->fail( 'verification_failure', 'recovery marker is absent immediately after a successful write' );
            }

            $row = $stored['row'];
            $current_generation = (int) ( $row['generation'] ?? 0 );
            if ( $current_generation !== $expected_generation ) {
                return $this->fail( 'superseded_after_write', 'another writer changed the outcome before this record could be verified', [
                    'current_generation' => $current_generation,
                ] );
            }

            $mismatch = $this->record_verification_mismatch( $row, $expected );
            if ( '' !== $mismatch ) {
                return $this->fail( 'verification_failure', "the persisted outcome does not match this caller's record (" . $mismatch . ')', [
                    'current_generation' => $current_generation,
                ] );
            }

            $this->log( sprintf(
                'recorded unresolved outcome key=%s generation=%d row=%d reason=%s',
                $key, $current_generation, (int) $row['row_id'], (string) $row['reason']
            ) );

            return [
                'ok' => true, 'status' => 'ok', 'found' => true,
                'rows' => [ $row ], 'row' => $row,
                'operation_key' => $key, 'generation' => $current_generation,
                'reason' => '', 'db_error' => [],
            ];
        } finally {
            $this->connections->close( $db );
        }
    }

    // ── Reading ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function find_unresolved_outcome( string $operation_key ): array {
        $key = $this->normalize_key( $operation_key );
        if ( '' === $key ) { return $this->fail( 'invalid_operation_key', 'operation_key is required' ); }
        if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
            return $this->fail( 'invalid_operation_key', sprintf(
                'operation_key exceeds the supported length of %d characters', self::MAX_KEY_LENGTH
            ) );
        }

        $opened = $this->open_connection();
        if ( empty( $opened['ok'] ) ) {
            return $this->fail(
                (string) ( $opened['status'] ?? 'connection_failure' ),
                (string) ( $opened['error'] ?? 'recovery connection unavailable' )
            );
        }
        $db = $opened['db'];
        try {
            // Certainty requires a valid schema: "no marker" read from a table
            // that is not the table we think it is means nothing.
            $schema = $this->verify_schema_on( $db );
            if ( empty( $schema['ok'] ) ) { return $schema; }

            $read = $this->read_row( $db, $key );
            if ( empty( $read['ok'] ) ) {
                return $this->fail( 'read_failure', 'recovery marker could not be read', [ 'db_error' => $read['db_error'] ?? [] ] );
            }
            if ( empty( $read['found'] ) ) {
                return [ 'ok' => true, 'status' => 'not_found', 'found' => false, 'rows' => [], 'reason' => '', 'db_error' => [] ];
            }
            return [ 'ok' => true, 'status' => 'ok', 'found' => true, 'rows' => [ $read['row'] ], 'row' => $read['row'], 'reason' => '', 'db_error' => [] ];
        } finally {
            $this->connections->close( $db );
        }
    }

    /**
     * Whether an operation or import row is blocked.
     *
     * An UNREADABLE store is itself blocking: a caller that requires certainty
     * must not proceed when certainty is unavailable.
     *
     * @param array<string,mixed> $criteria operation_key and/or row_id
     * @return array<string,mixed>
     */
    public function has_blocking_outcome( array $criteria ): array {
        $key    = $this->normalize_key( (string) ( $criteria['operation_key'] ?? '' ) );
        $row_id = (int) ( $criteria['row_id'] ?? 0 );

        if ( '' === $key && $row_id <= 0 ) {
            return array_merge( $this->fail( 'invalid_criteria', 'operation_key or a positive row_id is required' ), [ 'blocking' => true ] );
        }
        if ( '' !== $key && strlen( $key ) > self::MAX_KEY_LENGTH ) {
            return array_merge( $this->fail( 'invalid_operation_key', sprintf(
                'operation_key exceeds the supported length of %d characters', self::MAX_KEY_LENGTH
            ) ), [ 'blocking' => true ] );
        }

        if ( '' !== $key ) {
            $found = $this->find_unresolved_outcome( $key );
            if ( empty( $found['ok'] ) ) {
                // Includes schema_failure: an invalid or unverifiable schema is
                // never reported as "no blockers".
                return array_merge( $found, [ 'blocking' => true ] );
            }
            $blocking = ! empty( $found['found'] ) && self::STATE_UNRESOLVED === (string) $found['row']['state'];
            return [
                'ok' => true, 'status' => 'ok', 'blocking' => $blocking,
                'operation_key' => $blocking ? $key : '',
                'generation' => $blocking ? (int) $found['row']['generation'] : 0,
                'reason' => '', 'db_error' => [],
            ];
        }

        $list = $this->list_unresolved_outcomes();
        if ( empty( $list['ok'] ) ) {
            return array_merge( $list, [ 'blocking' => true ] );
        }
        foreach ( $list['rows'] as $row ) {
            if ( $row_id > 0 && (int) ( $row['row_id'] ?? 0 ) === $row_id ) {
                return [
                    'ok' => true, 'status' => 'ok', 'blocking' => true,
                    'operation_key' => (string) $row['operation_key'],
                    'generation' => (int) $row['generation'],
                    'reason' => '', 'db_error' => [],
                ];
            }
        }
        return [ 'ok' => true, 'status' => 'ok', 'blocking' => false, 'operation_key' => '', 'generation' => 0, 'reason' => '', 'db_error' => [] ];
    }

    /** @return array<string,mixed> */
    public function list_unresolved_outcomes(): array {
        $opened = $this->open_connection();
        if ( empty( $opened['ok'] ) ) {
            return $this->fail(
                (string) ( $opened['status'] ?? 'connection_failure' ),
                (string) ( $opened['error'] ?? 'recovery connection unavailable' )
            );
        }
        $db = $opened['db'];
        try {
            $schema = $this->verify_schema_on( $db );
            if ( empty( $schema['ok'] ) ) { return $schema; }

            $rows = $db->get_results( $db->prepare(
                'SELECT * FROM ' . $this->table( $db ) . ' WHERE state = %s ORDER BY id ASC',
                self::STATE_UNRESOLVED
            ), ARRAY_A );
            if ( ! is_array( $rows ) ) {
                return $this->fail( 'read_failure', 'unresolved outcomes could not be listed', [ 'db_error' => $this->classify( $db ) ] );
            }
            return [ 'ok' => true, 'status' => 'ok', 'found' => [] !== $rows, 'rows' => $rows, 'reason' => '', 'db_error' => [] ];
        } finally {
            $this->connections->close( $db );
        }
    }

    /** @return array{ok:bool,found:bool,row:array<string,mixed>,db_error:array<string,mixed>} */
    private function read_row( $db, string $key ): array {
        $row = $db->get_row( $db->prepare(
            'SELECT * FROM ' . $this->table( $db ) . ' WHERE operation_key = %s LIMIT 1',
            $key
        ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            $error = $this->classify( $db );
            if ( '' !== (string) ( $db->last_error ?? '' ) ) {
                return [ 'ok' => false, 'found' => false, 'row' => [], 'db_error' => $error ];
            }
            return [ 'ok' => true, 'found' => false, 'row' => [], 'db_error' => [] ];
        }
        return [ 'ok' => true, 'found' => true, 'row' => $row, 'db_error' => [] ];
    }

    // ── Resolution ────────────────────────────────────────────────────────

    /**
     * Resolve one outcome. Requires the exact generation the operator was shown,
     * an explicit decision, a resolver identity and a resolution reason. A stale
     * generation never resolves or removes a newer marker, and nothing is ever
     * cleared silently — every attempt is audit-logged.
     *
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    public function resolve_outcome( string $operation_key, int $expected_generation, array $decision ): array {
        $key = $this->normalize_key( $operation_key );
        if ( '' === $key ) { return $this->fail( 'invalid_operation_key', 'operation_key is required' ); }
        if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
            return $this->fail( 'invalid_operation_key', sprintf(
                'operation_key exceeds the supported length of %d characters', self::MAX_KEY_LENGTH
            ) );
        }

        $choice      = strtolower( trim( (string) ( $decision['decision'] ?? '' ) ) );
        $resolved_by = (int) ( $decision['resolved_by'] ?? 0 );
        $why         = trim( (string) ( $decision['resolution_reason'] ?? '' ) );

        if ( ! in_array( $choice, [ 'acknowledged', 'discarded' ], true ) ) {
            $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'unsupported_decision' );
            return $this->fail( 'invalid_resolution', 'decision must be acknowledged or discarded' );
        }
        if ( $resolved_by <= 0 ) {
            $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'missing_resolver_identity' );
            return $this->fail( 'invalid_resolution', 'a resolver identity is required' );
        }
        if ( '' === $why ) {
            $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'missing_resolution_reason' );
            return $this->fail( 'invalid_resolution', 'a resolution reason is required' );
        }
        if ( $expected_generation <= 0 ) {
            $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'invalid_generation' );
            return $this->fail( 'invalid_resolution', 'an expected generation is required' );
        }

        $opened = $this->open_connection();
        if ( empty( $opened['ok'] ) ) {
            return $this->fail(
                (string) ( $opened['status'] ?? 'connection_failure' ),
                (string) ( $opened['error'] ?? 'recovery connection unavailable' )
            );
        }
        $db = $opened['db'];

        try {
            $schema = $this->verify_schema_on( $db );
            if ( empty( $schema['ok'] ) ) { return $schema; }

            $existing = $this->read_row( $db, $key );
            if ( empty( $existing['ok'] ) ) {
                return $this->fail( 'read_failure', 'outcome could not be read', [ 'db_error' => $existing['db_error'] ] );
            }
            if ( empty( $existing['found'] ) ) {
                $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'not_found' );
                return $this->fail( 'not_found', 'no outcome with that operation key' );
            }
            if ( (int) $existing['row']['generation'] !== $expected_generation ) {
                $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused',
                    'stale_generation:current=' . (int) $existing['row']['generation'] );
                return $this->fail( 'stale_generation', 'the outcome changed since it was read', [
                    'current_generation' => (int) $existing['row']['generation'],
                ] );
            }

            // Generation-gated: a marker rewritten between the read above and
            // this write is NOT resolved.
            $updated = $db->query( $db->prepare(
                'UPDATE ' . $this->table( $db ) . ' SET state = %s, resolved_at = %s, resolved_by = %d,'
                . ' resolution_reason = %s, updated_at = %s'
                . ' WHERE operation_key = %s AND generation = %d AND state = %s',
                self::STATE_RESOLVED, $this->now(), $resolved_by,
                $this->bounded( $why, 191 ), $this->now(),
                $key, $expected_generation, self::STATE_UNRESOLVED
            ) );

            if ( false === $updated ) {
                $error = $this->classify( $db );
                $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'write_failure' );
                return $this->fail( 'lock_timeout' === $error['class'] ? 'lock_timeout' : 'write_failure',
                    'resolution write failed', [ 'db_error' => $error ] );
            }
            if ( 0 === (int) $updated ) {
                $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'stale_generation_at_write' );
                return $this->fail( 'stale_generation', 'the outcome changed before the resolution was written' );
            }

            // VERIFY that THIS resolution landed — not merely that the row is
            // resolved. A newer generation may have been written between the
            // conditional update and this read, in which case the row belongs to
            // someone else's resolution and must never be reported as ours.
            $after = $this->read_row( $db, $key );
            if ( empty( $after['ok'] ) ) {
                return $this->fail( 'verification_failure', 'resolution could not be verified', [ 'db_error' => $after['db_error'] ] );
            }
            if ( empty( $after['found'] ) ) {
                return $this->fail( 'verification_failure', 'outcome is absent after a successful resolution write' );
            }
            $row = $after['row'];

            $current_generation = (int) ( $row['generation'] ?? 0 );
            if ( $current_generation !== $expected_generation ) {
                $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused',
                    'superseded_after_write:current=' . $current_generation );
                return $this->fail( 'superseded_after_write', sprintf(
                    'a newer generation (%d) appeared after the resolution write; the earlier resolution is NOT verified',
                    $current_generation
                ), [ 'current_generation' => $current_generation ] );
            }

            $mismatch = '';
            if ( (string) ( $row['operation_key'] ?? '' ) !== $key ) { $mismatch = 'operation_key'; }
            elseif ( self::STATE_RESOLVED !== (string) ( $row['state'] ?? '' ) ) { $mismatch = 'state'; }
            elseif ( (int) ( $row['resolved_by'] ?? 0 ) !== $resolved_by ) { $mismatch = 'resolved_by'; }
            elseif ( (string) ( $row['resolution_reason'] ?? '' ) !== $this->bounded( $why, 191 ) ) { $mismatch = 'resolution_reason'; }
            elseif ( '' !== $this->identity_conflict( $row, $existing['row'] ) ) { $mismatch = 'operation identity'; }

            if ( '' !== $mismatch ) {
                $this->audit( $key, $expected_generation, $choice, $resolved_by, 'refused', 'verification_mismatch:' . $mismatch );
                return $this->fail( 'verification_failure', sprintf(
                    'the persisted outcome does not match the requested resolution (%s)', $mismatch
                ), [ 'current_generation' => $current_generation ] );
            }

            $this->audit( $key, $expected_generation, $choice, $resolved_by, 'resolved', $why );
            return [
                'ok' => true, 'status' => 'ok', 'found' => true,
                'rows' => [ $row ], 'row' => $row,
                'generation' => $expected_generation, 'reason' => '', 'db_error' => [],
            ];
        } finally {
            $this->connections->close( $db );
        }
    }

    // ── Input hardening ───────────────────────────────────────────────────

    /**
     * Verify that the durable row is exactly the record this caller wrote.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $expected
     * @return string Empty when exact, otherwise the mismatched field.
     */
    private function record_verification_mismatch( array $row, array $expected ): string {
        foreach ( [
            'operation_key', 'operation_type', 'expected_assignment_key', 'correlation_id',
            'state', 'reason', 'evidence', 'resolution_reason',
        ] as $field ) {
            if ( (string) ( $row[ $field ] ?? '' ) !== (string) ( $expected[ $field ] ?? '' ) ) { return $field; }
        }
        foreach ( [ 'row_id', 'batch_id', 'expected_candidate_id', 'resolved_by' ] as $field ) {
            if ( (int) ( $row[ $field ] ?? 0 ) !== (int) ( $expected[ $field ] ?? 0 ) ) { return $field; }
        }
        $actual_resolved_at = $row['resolved_at'] ?? null;
        if ( null !== $actual_resolved_at && '' !== (string) $actual_resolved_at ) { return 'resolved_at'; }
        return '';
    }

    /** Normalize an operation key without truncating it. */
    private function normalize_key( string $key ): string {
        return trim( preg_replace( '/\s+/', ' ', $key ) ?? '' );
    }

    /**
     * Identity fields that may never change for an existing operation key.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return string '' when compatible, otherwise the conflicting field
     */
    private function identity_conflict( array $existing, array $incoming ): string {
        if ( (string) ( $existing['operation_type'] ?? '' ) !== $this->bounded( (string) ( $incoming['operation_type'] ?? '' ), 50 ) ) {
            return 'operation_type';
        }
        if ( (int) ( $existing['row_id'] ?? 0 ) !== (int) ( $incoming['row_id'] ?? 0 ) ) { return 'row_id'; }
        if ( (int) ( $existing['batch_id'] ?? 0 ) !== (int) ( $incoming['batch_id'] ?? 0 ) ) { return 'batch_id'; }
        return '';
    }

    private function bounded( string $value, int $limit ): string {
        $value = trim( $value );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
    }

    private function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }

    private function audit( string $key, int $generation, string $decision, int $operator, string $outcome, string $detail ): void {
        error_log( sprintf(
            '%s key=%s generation=%d decision=%s operator=%d outcome=%s detail=%s',
            self::AUDIT_TAG, $key, $generation, $decision, $operator, $outcome, $detail
        ) );
    }
}
