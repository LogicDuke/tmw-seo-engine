<?php
/**
 * TMW SEO Engine — Keyword Assignment Validation Fixture Repository (PR-F).
 *
 * Bookkeeping for PRODUCTION-VALIDATION FIXTURES ONLY: one row per explicit
 * operator-created validation fixture, used to safely prove two PR-E
 * guarantees in production without touching any production data:
 *
 *   A. manual_assignment — one controlled manual assignment (created through
 *      KeywordAssignmentRepository with the unmistakable
 *      source_type=manual_validation_fixture) that the PR-E executor must
 *      preserve as skipped;
 *   B. stale_plan — a reversible, fixture-scoped ANALYZER-INPUT override that
 *      makes the fresh migration plan for exactly one candidate differ from
 *      an approved reviewed snapshot, so the executor must mark that review
 *      stale before any write.
 *
 * OPT-IN ONLY: an active stale_plan fixture influences NOTHING by itself.
 * Ordinary migration analysis, review sync, and execute-approved never read
 * this table and behave byte-identically whether or not fixtures exist. The
 * override is applied exclusively when the explicit
 * `keyword-assignment-validation run-stale-validation` workflow verifies the
 * full validation context (token + review ID + candidate ID) against the
 * ACTIVE fixture row and passes the resulting override set as a PER-CALL
 * argument into the analyzer. There is no stored, static, or global override
 * state anywhere — nothing can leak between calls or requests.
 *
 * The stale_plan override NEVER mutates candidate rows, Rank Math metadata,
 * page content, postmeta, import evidence, or review snapshot hashes; it is
 * a pure in-memory transform of one evidence row.
 *
 * LIFECYCLE + AUDIT: fixture rows are never deleted — state transitions
 * (active -> removed for manual fixtures, active -> restored for stale
 * fixtures) keep the full history queryable forever. Every lifecycle event
 * additionally appends one row to the APPEND-ONLY fixture audit table
 * ({$prefix}tmw_keyword_assignment_validation_fixture_audit); the state
 * transition and its audit row are committed atomically inside the calling
 * service's transaction, and no update or delete path for audit rows exists
 * anywhere in the plugin.
 *
 * CONCURRENCY: while a fixture is active its row carries two identity
 * columns (active_token_key = the token, active_scope_key = the exact
 * candidate/assignment scope), each protected by a database UNIQUE index.
 * Both are set to NULL on the terminal transition. Duplicate or concurrent
 * create attempts therefore fail deterministically at the index — never
 * only at a pre-insert SELECT.
 *
 * FAIL-CLOSED JSON: encode_values() returns null on encoding failure and
 * every write path treats that as a hard abort (transaction rolled back,
 * error propagated to the CLI as a non-zero exit). No fixture or audit row
 * is ever persisted with fabricated empty JSON.
 *
 * PR-F SCOPE: validation tooling only. No production path (approval,
 * generation, Rank Math, publishing, indexing, ownership resolution) reads
 * this table or these classes; only the explicit CLI workflow does. Nothing
 * runs on plugin load. Logging is gated behind WP_DEBUG or
 * TMWSEO_KW_VALIDATION_DEBUG.
 *
 * Log tag: [TMW-KW-ASSIGN-VALIDATE]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.25
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentValidationFixtureRepository {

    public const LOG_TAG = '[TMW-KW-ASSIGN-VALIDATE]';

    public const TYPE_MANUAL = 'manual_assignment';
    public const TYPE_STALE  = 'stale_plan';
    public const TYPES = [ self::TYPE_MANUAL, self::TYPE_STALE ];

    /**
     * active   — the fixture is in force (manual assignment exists / stale
     *            override is available to the explicit validation workflow);
     * removed  — terminal state of a manual fixture after explicit cleanup;
     * restored — terminal state of a stale fixture after explicit restoration.
     */
    public const STATES = [ 'active', 'removed', 'restored' ];

    /** Unmistakable source_type written on every manual validation assignment. */
    public const MANUAL_SOURCE_TYPE = 'manual_validation_fixture';

    /** source_reference prefix carrying the validation token. */
    public const SOURCE_REFERENCE_PREFIX = 'validation:';

    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_assignment_validation_fixtures';
    }

    public function audit_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_assignment_validation_fixture_audit';
    }

    public function table_exists(): bool {
        return $this->named_table_exists( $this->table() );
    }

    public function tables_exist(): bool {
        return $this->named_table_exists( $this->table() ) && $this->named_table_exists( $this->audit_table() );
    }

    private function named_table_exists( string $table ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return is_string( $found ) && strtolower( $found ) === strtolower( $table );
    }

    // ── Token / scope discipline ──────────────────────────────────────────

    /**
     * Validation tokens are explicit operator input: 4–64 chars of
     * [A-Za-z0-9._-]. Anything else is rejected before any lookup or write.
     */
    public function normalize_token( string $token ): string {
        $token = trim( $token );
        return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{3,63}$/', $token ) ? $token : '';
    }

    public function source_reference_for_token( string $token ): string {
        return self::SOURCE_REFERENCE_PREFIX . $token;
    }

    /** Unique active-identity scope for a stale fixture: one per candidate. */
    public static function stale_scope_key( int $candidate_id ): string {
        return 'stale:candidate:' . $candidate_id;
    }

    /** Unique active-identity scope for a manual fixture: one per exact assignment identity. */
    public static function manual_scope_key( string $assignment_key ): string {
        return 'manual:assignment:' . $assignment_key;
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public function find_by_id( int $fixture_id ): ?array {
        if ( $fixture_id <= 0 || ! $this->table_exists() ) { return null; }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1',
            $fixture_id
        ), ARRAY_A );
        return is_array( $row ) ? $this->decode_row( $row ) : null;
    }

    /**
     * Latest fixture row for one token (any state). Tokens are reusable only
     * after their previous fixture reached a terminal state, so "latest by
     * id" is the authoritative record for the token.
     *
     * @return array<string,mixed>|null
     */
    public function find_latest_by_token( string $token, string $fixture_type = '' ): ?array {
        $token = $this->normalize_token( $token );
        if ( '' === $token || ! $this->table_exists() ) { return null; }
        global $wpdb;
        if ( '' !== $fixture_type ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE validation_token = %s AND fixture_type = %s ORDER BY id DESC LIMIT 1',
                $token,
                $fixture_type
            ), ARRAY_A );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE validation_token = %s ORDER BY id DESC LIMIT 1',
                $token
            ), ARRAY_A );
        }
        return is_array( $row ) ? $this->decode_row( $row ) : null;
    }

    /**
     * All ACTIVE fixtures, optionally narrowed by type and/or candidate.
     * Deterministic ordering by id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_active( string $fixture_type = '', int $candidate_id = 0 ): array {
        if ( ! $this->table_exists() ) { return []; }
        global $wpdb;
        $sql = 'SELECT * FROM ' . $this->table() . " WHERE state = 'active'";
        $args = [];
        if ( '' !== $fixture_type ) { $sql .= ' AND fixture_type = %s'; $args[] = $fixture_type; }
        if ( $candidate_id > 0 ) { $sql .= ' AND keyword_candidate_id = %d'; $args[] = $candidate_id; }
        $sql .= ' ORDER BY id ASC';
        $rows = [] === $args ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
        return is_array( $rows ) ? array_map( [ $this, 'decode_row' ], $rows ) : [];
    }

    /** @return array<string,int> counts per "type/state" */
    public function state_counts(): array {
        if ( ! $this->table_exists() ) { return []; }
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT fixture_type, state, COUNT(*) AS n FROM ' . $this->table() . ' GROUP BY fixture_type, state ORDER BY fixture_type ASC, state ASC',
            ARRAY_A
        );
        $counts = [];
        foreach ( (array) $rows as $row ) {
            $counts[ (string) $row['fixture_type'] . '/' . (string) $row['state'] ] = (int) $row['n'];
        }
        return $counts;
    }

    /** @return array<int,array<string,mixed>> audit events for one fixture, oldest first */
    public function audit_for_fixture( int $fixture_id ): array {
        if ( $fixture_id <= 0 || ! $this->tables_exist() ) { return []; }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . $this->audit_table() . ' WHERE fixture_id = %d ORDER BY id ASC',
            $fixture_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    // ── Writes (explicit CLI workflow only; caller owns the transaction) ──

    /**
     * Insert one fixture row in state 'active' PLUS its 'created' audit row.
     * Fails closed on invalid token/type, a missing active scope key, a JSON
     * encoding failure, a duplicate active identity (database unique index —
     * never only the pre-insert SELECT), or an audit insertion failure. The
     * CALLER must wrap this in a transaction and roll back on any error so
     * the fixture row and its audit row commit atomically or not at all.
     *
     * @param array<string,mixed> $fixture
     * @return array{ok:bool,id?:int,error?:string}
     */
    public function create_fixture( array $fixture, string $actor = 'cli', string $source = 'keyword-assignment-validation' ): array {
        if ( ! $this->tables_exist() ) { return [ 'ok' => false, 'error' => 'validation_fixture_tables_missing' ]; }
        $token = $this->normalize_token( (string) ( $fixture['validation_token'] ?? '' ) );
        if ( '' === $token ) { return [ 'ok' => false, 'error' => 'invalid_validation_token' ]; }
        $type = (string) ( $fixture['fixture_type'] ?? '' );
        if ( ! in_array( $type, self::TYPES, true ) ) { return [ 'ok' => false, 'error' => 'invalid_fixture_type' ]; }
        $candidate_id = (int) ( $fixture['keyword_candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 ) { return [ 'ok' => false, 'error' => 'missing_keyword_candidate_id' ]; }
        $scope_key = trim( (string) ( $fixture['active_scope_key'] ?? '' ) );
        if ( '' === $scope_key ) { return [ 'ok' => false, 'error' => 'missing_active_scope_key' ]; }

        $existing = $this->find_latest_by_token( $token );
        if ( null !== $existing && 'active' === (string) $existing['state'] ) {
            return [ 'ok' => false, 'error' => 'token_already_has_active_fixture', 'id' => (int) $existing['id'] ];
        }

        // FAIL CLOSED on JSON encoding: no fixture row may ever carry
        // fabricated empty JSON in place of its real values.
        $original_encoded = $this->encode_values( $fixture['original_values'] ?? [] );
        $override_encoded = $this->encode_values( $fixture['override_values'] ?? [] );
        if ( null === $original_encoded || null === $override_encoded ) {
            return [ 'ok' => false, 'error' => 'fixture_values_encode_failed' ];
        }

        $row = [
            'validation_token'     => $token,
            'fixture_type'         => $type,
            'keyword_candidate_id' => $candidate_id,
            'review_id'            => max( 0, (int) ( $fixture['review_id'] ?? 0 ) ),
            'assignment_id'        => max( 0, (int) ( $fixture['assignment_id'] ?? 0 ) ),
            'original_values'      => $original_encoded,
            'override_values'      => $override_encoded,
            'state'                => 'active',
            'active_token_key'     => $token,
            'active_scope_key'     => $this->sanitize_text( $scope_key, 191 ),
            'created_by'           => $this->sanitize_text( (string) ( $fixture['created_by'] ?? '' ), 191 ),
            'created_at'           => $this->now(),
            'restored_at'          => null,
        ];
        $id = $this->insert_row( $row );
        if ( $id <= 0 ) {
            // The UNIQUE active-identity indexes are the real concurrency
            // barrier: a race that slipped past the pre-insert SELECT dies
            // here deterministically.
            if ( $this->active_identity_taken( $token, (string) $row['active_scope_key'] ) ) {
                return [ 'ok' => false, 'error' => 'duplicate_active_fixture_identity' ];
            }
            return [ 'ok' => false, 'error' => 'fixture_insert_failed' ];
        }
        $stored = $row;
        $stored['id'] = $id;
        if ( ! $this->audit_event( $stored, 'created', '', 'active', $actor, (string) ( $fixture['audit_note'] ?? '' ), $source ) ) {
            return [ 'ok' => false, 'error' => 'fixture_audit_insert_failed' ];
        }
        $this->log( sprintf( 'fixture created id=%d token=%s type=%s candidate=%d review=%d', $id, $token, $type, $candidate_id, (int) $row['review_id'] ) );
        return [ 'ok' => true, 'id' => $id ];
    }

    /**
     * Move one ACTIVE fixture to its terminal state and append the matching
     * audit row. Only active->removed (manual) and active->restored (stale)
     * exist; any other transition fails closed. Both active-identity columns
     * are cleared so the token and scope become reusable. Fixture rows are
     * never deleted. The CALLER must wrap this in a transaction and roll
     * back on any error so the state transition and its audit row commit
     * atomically or not at all.
     *
     * @return array{ok:bool,error?:string}
     */
    public function close_fixture( int $fixture_id, string $terminal_state, string $actor = 'cli', string $note = '', string $source = 'keyword-assignment-validation' ): array {
        if ( ! in_array( $terminal_state, [ 'removed', 'restored' ], true ) ) {
            return [ 'ok' => false, 'error' => 'invalid_terminal_state' ];
        }
        $stored = $this->find_by_id( $fixture_id );
        if ( null === $stored ) { return [ 'ok' => false, 'error' => 'fixture_not_found' ]; }
        if ( 'active' !== (string) $stored['state'] ) {
            return [ 'ok' => false, 'error' => 'fixture_not_active' ];
        }
        $expected = self::TYPE_MANUAL === (string) $stored['fixture_type'] ? 'removed' : 'restored';
        if ( $terminal_state !== $expected ) {
            return [ 'ok' => false, 'error' => 'terminal_state_mismatch_for_type' ];
        }
        $fields = [
            'state'            => $terminal_state,
            'active_token_key' => null,
            'active_scope_key' => null,
            'restored_at'      => $this->now(),
        ];
        if ( ! $this->update_row( $fixture_id, $fields ) ) {
            return [ 'ok' => false, 'error' => 'fixture_update_failed' ];
        }
        $action = 'removed' === $terminal_state ? 'manual_fixture_removed' : 'stale_fixture_restored';
        if ( ! $this->audit_event( $stored, $action, 'active', $terminal_state, $actor, $note, $source ) ) {
            return [ 'ok' => false, 'error' => 'fixture_audit_insert_failed' ];
        }
        $this->log( sprintf( 'fixture %d -> %s (token=%s)', $fixture_id, $terminal_state, (string) $stored['validation_token'] ) );
        return [ 'ok' => true ];
    }

    /**
     * Append one row to the APPEND-ONLY fixture audit table. The payload
     * hash pins the normalized metadata snapshot of the fixture at event
     * time; a JSON encoding failure fails the event (and, through the
     * caller's transaction, the state transition it belongs to). Returns
     * false on encoding or insert failure — never inserts partial data.
     *
     * @param array<string,mixed> $fixture stored fixture row (decoded or raw)
     */
    public function audit_event( array $fixture, string $action, string $old_state, string $new_state, string $actor, string $note, string $source ): bool {
        if ( ! $this->tables_exist() ) { return false; }
        $snapshot = [
            'fixture_id'           => (int) ( $fixture['id'] ?? 0 ),
            'validation_token'     => (string) ( $fixture['validation_token'] ?? '' ),
            'fixture_type'         => (string) ( $fixture['fixture_type'] ?? '' ),
            'keyword_candidate_id' => (int) ( $fixture['keyword_candidate_id'] ?? 0 ),
            'review_id'            => (int) ( $fixture['review_id'] ?? 0 ),
            'assignment_id'        => (int) ( $fixture['assignment_id'] ?? 0 ),
            'action'               => $action,
            'old_state'            => $old_state,
            'new_state'            => $new_state,
        ];
        $encoded = $this->encode_values( $snapshot );
        if ( null === $encoded ) { return false; }
        return $this->insert_audit_row( [
            'fixture_id'       => (int) ( $fixture['id'] ?? 0 ),
            'validation_token' => $this->sanitize_text( (string) ( $fixture['validation_token'] ?? '' ), 64 ),
            'fixture_type'     => $this->sanitize_text( (string) ( $fixture['fixture_type'] ?? '' ), 30 ),
            'action'           => $this->sanitize_text( $action, 40 ),
            'old_state'        => $this->sanitize_text( $old_state, 20 ),
            'new_state'        => $this->sanitize_text( $new_state, 20 ),
            'actor'            => $this->sanitize_text( $actor, 191 ),
            'note'             => $this->sanitize_text( $note, 500 ),
            'command_source'   => $this->sanitize_text( $source, 100 ),
            'payload_hash'     => sha1( $encoded ),
            'created_at'       => $this->now(),
        ] );
    }

    // ── Stale-plan override application (in-memory, evidence stream only) ─

    /**
     * Apply explicit stale_plan overrides to ONE ownership-report evidence
     * row. Pure per-call transform: the incoming row is copied, never
     * referenced storage, and there is no stored/static override source.
     * Rows for candidates without an override entry are returned unchanged.
     *
     * Supported override kind:
     *   content_presence — force the content-presence flag of one page id in
     *   this candidate's evidence to a fixed value. This changes the
     *   analyzer's planned present_in_content for that target (and only
     *   in-memory analysis output), which makes the fresh plan differ from
     *   the reviewed snapshot.
     *
     * @param array<string,mixed>            $evidence_row
     * @param array<int,array<string,mixed>> $overrides candidate_id => override
     * @return array<string,mixed>
     */
    public static function apply_stale_overrides_to_row( array $evidence_row, array $overrides ): array {
        $candidate_id = (int) ( $evidence_row['candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 || ! isset( $overrides[ $candidate_id ] ) ) { return $evidence_row; }
        $override = (array) $overrides[ $candidate_id ];
        if ( 'content_presence' !== (string) ( $override['kind'] ?? '' ) ) { return $evidence_row; }
        $post_id = (int) ( $override['post_id'] ?? 0 );
        if ( $post_id <= 0 ) { return $evidence_row; }
        $present = ! empty( $override['present'] );

        $entries = (array) ( $evidence_row['content_presence'] ?? [] );
        $found = false;
        foreach ( $entries as $index => $entry ) {
            if ( (int) ( $entry['post_id'] ?? 0 ) === $post_id ) {
                $entries[ $index ]['present'] = $present;
                $found = true;
            }
        }
        if ( ! $found ) {
            $entries[] = [ 'post_id' => $post_id, 'present' => $present ];
        }
        $evidence_row['content_presence'] = array_values( $entries );
        return $evidence_row;
    }

    // ── Storage primitives (overridable for in-memory testing) ────────────

    /** @param array<string,mixed> $row @return int 0 on failure */
    protected function insert_row( array $row ): int {
        global $wpdb;
        if ( ! $this->table_exists() ) { return 0; }
        $ok = $wpdb->insert( $this->table(), $row );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $fields */
    protected function update_row( int $fixture_id, array $fields ): bool {
        global $wpdb;
        if ( $fixture_id <= 0 || [] === $fields || ! $this->table_exists() ) { return false; }
        return false !== $wpdb->update( $this->table(), $fields, [ 'id' => $fixture_id ] );
    }

    /** APPEND-ONLY: the single insert path for audit rows; no update/delete exists. */
    protected function insert_audit_row( array $row ): bool {
        global $wpdb;
        if ( ! $this->tables_exist() ) { return false; }
        return false !== $wpdb->insert( $this->audit_table(), $row );
    }

    /** True when an ACTIVE fixture already occupies the token or scope identity. */
    protected function active_identity_taken( string $token, string $scope_key ): bool {
        $latest = $this->find_latest_by_token( $token );
        if ( null !== $latest && 'active' === (string) $latest['state'] ) { return true; }
        foreach ( $this->list_active() as $fixture ) {
            if ( (string) ( $fixture['active_scope_key'] ?? '' ) === $scope_key ) { return true; }
        }
        return false;
    }

    /** Transaction primitive shared with the validation service. */
    public function transaction( string $command ): bool {
        global $wpdb;
        return false !== $wpdb->query( $command );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode_row( array $row ): array {
        foreach ( [ 'original_values', 'override_values' ] as $field ) {
            $decoded = json_decode( (string) ( $row[ $field ] ?? '' ), true );
            $row[ $field ] = is_array( $decoded ) ? $decoded : [];
        }
        return $row;
    }

    /**
     * FAIL-CLOSED JSON encoding: null on failure, never a fabricated
     * fallback. Every caller must abort (and roll back) on null.
     *
     * @param mixed $values
     */
    protected function encode_values( $values ): ?string {
        $encoded = function_exists( 'wp_json_encode' )
            ? wp_json_encode( (array) $values, JSON_UNESCAPED_SLASHES )
            : json_encode( (array) $values, JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : null;
    }

    private function sanitize_text( string $value, int $max ): string {
        $value = trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '' );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    protected function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    /** Validation logging is opt-in: WP_DEBUG or TMWSEO_KW_VALIDATION_DEBUG. */
    public static function debug_logging_enabled(): bool {
        return ( defined( 'WP_DEBUG' ) && WP_DEBUG )
            || ( defined( 'TMWSEO_KW_VALIDATION_DEBUG' ) && TMWSEO_KW_VALIDATION_DEBUG );
    }

    private function log( string $message ): void {
        if ( self::debug_logging_enabled() ) {
            error_log( self::LOG_TAG . ' ' . $message );
        }
    }
}
