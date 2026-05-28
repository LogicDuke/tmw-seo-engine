<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Repository for video-intent keyword candidates stored in tmw_keyword_candidates.
 *
 * This class intentionally does not create tables, update Rank Math metadata, or
 * modify posts. It only defines safe conventions for rows that already fit the
 * central keyword candidate table.
 */
class VideoKeywordCandidateRepository {
    private const LOG_CONTEXT = 'video_keyword_candidates';
    private const LOG_TAG = '[TMW-VIDEO-KW]';
    private const INTENT_TYPE = 'video';
    private const DEFAULT_ENTITY_TYPE = 'post';
    private const ALLOWED_ENTITY_TYPES = [ 'post', 'video' ];
    private const ALLOWED_STATUSES = [ 'candidate', 'reviewed', 'approved', 'rejected', 'archived' ];
    private const DEFAULT_STATUS = 'candidate';

    /** @var array<string,array<string,bool>> */
    private static array $columns_cache = [];

    public function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_candidates';
    }

    public function table_exists(): bool {
        global $wpdb;
        $table = $this->table_name();
        $like  = $wpdb->esc_like( $table );
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        $exists = is_string( $found ) && strtolower( $found ) === strtolower( $table );
        if ( ! $exists ) {
            $this->log( 'warn', 'repository_missing_table', [ 'table' => $table ] );
        }

        return $exists;
    }

    public function upsert_for_video( int $post_id, string $keyword, array $args = [] ): int {
        global $wpdb;

        $post_id = max( 0, $post_id );
        $keyword = $this->normalize_keyword( $keyword );
        if ( $post_id <= 0 || $keyword === '' ) {
            return 0;
        }

        $model_name = (string) ( $args['model_name'] ?? '' );
        if ( $this->contains_unsafe_term( $keyword ) ) {
            $this->log( 'warn', 'candidate_rejected_unsafe', [ 'post_id' => $post_id, 'keyword' => $keyword ] );
            return 0;
        }
        if ( ! $this->is_video_intent_keyword( $keyword, $model_name ) ) {
            $this->log( 'info', 'candidate_rejected_non_video_intent', [ 'post_id' => $post_id, 'keyword' => $keyword ] );
            return 0;
        }

        if ( ! $this->table_exists() ) {
            return 0;
        }

        $columns = $this->columns();
        if ( empty( $columns['keyword'] ) ) {
            $this->log( 'warn', 'repository_missing_column', [ 'table' => $this->table_name(), 'column' => 'keyword' ] );
            return 0;
        }

        $existing_id = $this->find_existing_id( $post_id, $keyword, $columns );
        $now         = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $status      = $this->sanitize_status( (string) ( $args['status'] ?? self::DEFAULT_STATUS ) );
        $entity_type = $this->sanitize_entity_type( (string) ( $args['entity_type'] ?? self::DEFAULT_ENTITY_TYPE ) );

        $data = [ 'keyword' => $keyword ];
        if ( ! empty( $columns['canonical'] ) ) {
            $data['canonical'] = (string) ( $args['canonical'] ?? $keyword );
        }
        if ( ! empty( $columns['status'] ) ) {
            $data['status'] = $status;
        }
        if ( ! empty( $columns['intent'] ) ) {
            $data['intent'] = (string) ( $args['intent'] ?? self::INTENT_TYPE );
        }
        if ( ! empty( $columns['intent_type'] ) ) {
            $data['intent_type'] = self::INTENT_TYPE;
        }
        if ( ! empty( $columns['entity_type'] ) ) {
            $data['entity_type'] = $entity_type;
        }
        if ( ! empty( $columns['entity_id'] ) ) {
            $data['entity_id'] = $post_id;
        }

        foreach ( [ 'volume', 'cpc', 'difficulty', 'opportunity' ] as $metric ) {
            if ( array_key_exists( $metric, $args ) && ! empty( $columns[ $metric ] ) ) {
                $data[ $metric ] = $args[ $metric ];
            }
        }

        if ( ! empty( $columns['sources'] ) ) {
            $data['sources'] = $this->encode_maybe_json( $args['sources'] ?? [ 'video_generate' ] );
        }
        if ( ! empty( $columns['notes'] ) && array_key_exists( 'notes', $args ) ) {
            $data['notes'] = is_array( $args['notes'] ) ? $this->encode_maybe_json( $args['notes'] ) : (string) $args['notes'];
        }
        if ( ! empty( $columns['updated_at'] ) ) {
            $data['updated_at'] = $now;
        }

        if ( $existing_id > 0 ) {
            $updated = $wpdb->update( $this->table_name(), $data, [ 'id' => $existing_id ] );
            if ( $updated === false ) {
                return 0;
            }
            $this->log( 'info', 'candidate_upserted', [ 'post_id' => $post_id, 'keyword' => $keyword, 'id' => $existing_id ] );
            return $existing_id;
        }

        $inserted = $wpdb->insert( $this->table_name(), $data );
        if ( $inserted === false ) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;
        $this->log( 'info', 'candidate_upserted', [ 'post_id' => $post_id, 'keyword' => $keyword, 'id' => $id ] );
        return $id;
    }

    public function list_for_video( int $post_id, array $args = [] ): array {
        global $wpdb;

        $post_id = max( 0, $post_id );
        if ( $post_id <= 0 || ! $this->table_exists() ) {
            return [];
        }

        $columns = $this->columns();
        if ( empty( $columns['keyword'] ) ) {
            $this->log( 'warn', 'repository_missing_column', [ 'table' => $this->table_name(), 'column' => 'keyword' ] );
            return [];
        }

        $where = [ '1=1' ];
        $params = [];
        if ( ! empty( $columns['intent_type'] ) ) {
            $where[] = 'intent_type = %s';
            $params[] = self::INTENT_TYPE;
        }
        if ( ! empty( $columns['entity_id'] ) ) {
            $where[] = 'entity_id = %d';
            $params[] = $post_id;
        }
        if ( ! empty( $columns['entity_type'] ) ) {
            $where[] = "entity_type IN ('post','video')";
        }
        if ( ! empty( $args['status'] ) && ! empty( $columns['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = $this->sanitize_status( (string) $args['status'] );
        }

        $limit = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
        $order = $this->order_clause( $columns );
        $sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ' . $order . ' LIMIT %d';
        $params[] = $limit;

        $prepared = $wpdb->prepare( $sql, ...$params );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : [];

        $this->log( 'info', 'candidate_listed', [ 'post_id' => $post_id, 'count' => count( $rows ) ] );
        return $rows;
    }

    public function list_approved_for_video( int $post_id, int $limit = 5 ): array {
        return $this->list_for_video( $post_id, [ 'status' => 'approved', 'limit' => min( 5, max( 1, $limit ) ) ] );
    }

    public function delete_for_video( int $post_id, string $keyword ): bool {
        global $wpdb;

        $post_id = max( 0, $post_id );
        $keyword = $this->normalize_keyword( $keyword );
        if ( $post_id <= 0 || $keyword === '' || ! $this->table_exists() ) {
            return false;
        }

        $columns = $this->columns();
        $where = [ 'keyword' => $keyword ];
        if ( ! empty( $columns['intent_type'] ) ) {
            $where['intent_type'] = self::INTENT_TYPE;
        }
        if ( ! empty( $columns['entity_id'] ) ) {
            $where['entity_id'] = $post_id;
        }

        return $wpdb->delete( $this->table_name(), $where ) !== false;
    }

    public function normalize_keyword( string $keyword ): string {
        $keyword = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $keyword ) : strip_tags( $keyword );
        $keyword = html_entity_decode( $keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $keyword = strtolower( $keyword );
        $keyword = preg_replace( '/[\x{2018}\x{2019}\x{201A}\x{201B}]/u', "'", $keyword );
        $keyword = preg_replace( '/[\x{201C}\x{201D}\x{201E}\x{201F}]/u', '"', $keyword );
        $keyword = preg_replace( '/[^\p{L}\p{N}\s\'"-]+/u', ' ', $keyword );
        $keyword = preg_replace( '/\s+/u', ' ', (string) $keyword );
        return trim( (string) $keyword );
    }

    public function is_video_intent_keyword( string $keyword, string $model_name = '' ): bool {
        $keyword = $this->normalize_keyword( $keyword );
        $model   = $this->normalize_keyword( $model_name );

        if ( $keyword === '' || $this->contains_unsafe_term( $keyword ) ) {
            return false;
        }
        if ( $model !== '' && $keyword === $model ) {
            return false;
        }

        foreach ( [ 'adult webcam', 'webcam earnings', 'cam profile' ] as $blocked_phrase ) {
            if ( str_contains( $keyword, $blocked_phrase ) ) {
                return false;
            }
        }

        foreach ( [ 'webcam video', 'video chat', 'live webcam clip', 'cam show', 'live webcam session' ] as $phrase ) {
            if ( str_contains( $keyword, $phrase ) ) {
                return true;
            }
        }

        return (bool) preg_match( '/\b(video|clip|clips|webcam|cam show|video chat|session)\b/u', $keyword );
    }

    private function contains_unsafe_term( string $keyword ): bool {
        $keyword = $this->normalize_keyword( $keyword );
        return (bool) preg_match( '/\b(cam\s+porn|porn|xxx|sex|fuck|nude|naked)\b/u', $keyword );
    }

    private function sanitize_status( string $status ): string {
        $status = function_exists( 'sanitize_key' ) ? sanitize_key( $status ) : strtolower( preg_replace( '/[^a-z0-9_-]/', '', $status ) );
        return in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : self::DEFAULT_STATUS;
    }

    private function sanitize_entity_type( string $entity_type ): string {
        $entity_type = function_exists( 'sanitize_key' ) ? sanitize_key( $entity_type ) : strtolower( preg_replace( '/[^a-z0-9_-]/', '', $entity_type ) );
        return in_array( $entity_type, self::ALLOWED_ENTITY_TYPES, true ) ? $entity_type : self::DEFAULT_ENTITY_TYPE;
    }

    private function encode_maybe_json( $value ): string {
        if ( is_string( $value ) ) {
            return $value;
        }
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
        return is_string( $encoded ) ? $encoded : '';
    }

    /** @return array<string,bool> */
    private function columns(): array {
        global $wpdb;
        $table = $this->table_name();
        if ( isset( self::$columns_cache[ $table ] ) ) {
            return self::$columns_cache[ $table ];
        }

        $columns = [];
        if ( method_exists( $wpdb, 'get_results' ) ) {
            $rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $field = is_array( $row ) ? (string) ( $row['Field'] ?? $row['field'] ?? '' ) : '';
                    if ( $field !== '' ) {
                        $columns[ $field ] = true;
                    }
                }
            }
        }

        if ( empty( $columns ) ) {
            foreach ( [ 'id', 'keyword', 'canonical', 'status', 'intent', 'intent_type', 'entity_type', 'entity_id', 'volume', 'cpc', 'difficulty', 'opportunity', 'sources', 'notes', 'updated_at' ] as $field ) {
                $columns[ $field ] = true;
            }
        }

        self::$columns_cache[ $table ] = $columns;
        return $columns;
    }

    /** @param array<string,bool> $columns */
    private function find_existing_id( int $post_id, string $keyword, array $columns ): int {
        global $wpdb;
        if ( empty( $columns['id'] ) ) {
            return 0;
        }

        $where = [ 'keyword = %s' ];
        $params = [ $keyword ];
        if ( ! empty( $columns['intent_type'] ) ) {
            $where[] = 'intent_type = %s';
            $params[] = self::INTENT_TYPE;
        }
        if ( ! empty( $columns['entity_id'] ) ) {
            $where[] = 'entity_id = %d';
            $params[] = $post_id;
        }

        $sql = 'SELECT id FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1';
        $id = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
        return (int) $id;
    }

    /** @param array<string,bool> $columns */
    private function order_clause( array $columns ): string {
        $parts = [];
        if ( ! empty( $columns['notes'] ) ) {
            $parts[] = "CASE WHEN notes LIKE '%\"primary\":true%' OR notes LIKE '%primary:1%' THEN 0 ELSE 1 END ASC";
        }
        if ( ! empty( $columns['opportunity'] ) ) {
            $parts[] = 'opportunity DESC';
        }
        if ( ! empty( $columns['volume'] ) ) {
            $parts[] = 'volume DESC';
        }
        if ( ! empty( $columns['updated_at'] ) ) {
            $parts[] = 'updated_at DESC';
        }
        if ( ! empty( $columns['id'] ) ) {
            $parts[] = 'id DESC';
        }
        return 'ORDER BY ' . implode( ', ', $parts ?: [ 'keyword ASC' ] );
    }

    private function log( string $level, string $event, array $data = [] ): void {
        $message = self::LOG_TAG . ' ' . $event;
        if ( class_exists( Logs::class ) ) {
            if ( method_exists( Logs::class, $level ) ) {
                Logs::{$level}( self::LOG_CONTEXT, $message, $data );
                return;
            }
            Logs::info( self::LOG_CONTEXT, $message, $data );
            return;
        }

        error_log( $message . ' ' . wp_json_encode( $data ) );
    }
}
