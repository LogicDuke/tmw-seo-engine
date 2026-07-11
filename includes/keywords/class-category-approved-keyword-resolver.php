<?php
/**
 * Resolves approved keyword pool candidates for a single tmw_category_page post.
 *
 * This class mirrors the status-gating discipline of ClassifiedModelKeywordProvider
 * but is scoped exclusively to intent_type = 'category' rows. It never auto-approves,
 * never mutates DB rows, never calls external APIs, never publishes, and never touches
 * indexing or canonical fields.
 *
 * Safe status rule: ONLY status = 'approved' rows are returned for content use.
 * All other statuses (queued_for_review, new, discovered, scored, rejected, ignored)
 * are silently skipped and logged.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.4
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryApprovedKeywordResolver {

    /**
     * The only DB status value safe for public content generation.
     */
    private const SAFE_STATUS = 'approved';

    /**
     * DB statuses that must never be used in generated content.
     * Listed explicitly for clarity and auditability.
     */
    private const BLOCKED_STATUSES = [
        'queued_for_review',
        'new',
        'discovered',
        'scored',
        'rejected',
        'ignored',
    ];

    /**
     * Hard cap on total rows fetched from DB per call to prevent runaway queries.
     */
    private const DB_FETCH_LIMIT = 40;

    /**
     * Shared column existence cache keyed by table name.
     *
     * @var array<string, array<string, bool>>
     */
    private static array $columns_cache = [];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Resolve approved keyword candidates for one category page.
     *
     * Returns two buckets:
     *   - rankmath_extras : top $rankmath_limit terms for Rank Math extra keyword slots
     *   - content_terms   : up to $content_limit additional terms for body content use
     *
     * Both buckets are deduplicated against the focus keyword and against each other.
     * The focus keyword is never included in either bucket.
     *
     * @param int    $post_id        The tmw_category_page post ID.
     * @param string $focus_keyword  The page focus / primary keyword to exclude from extras.
     * @param int    $rankmath_limit Maximum terms for Rank Math extras (default 4).
     * @param int    $content_limit  Maximum terms for content body use (default 16).
     *
     * @return array{
     *   rankmath_extras: string[],
     *   content_terms: string[],
     *   pool_count: int,
     *   source: string,
     *   skipped: array<int, array{term: string, status: string, reason: string}>,
     * }
     */
    public function resolve_for_category(
        int    $post_id,
        string $focus_keyword,
        int    $rankmath_limit = 4,
        int    $content_limit  = 16
    ): array {
        $empty = [
            'rankmath_extras' => [],
            'content_terms'   => [],
            'pool_count'      => 0,
            'source'          => 'category_db_approved',
            'skipped'         => [],
        ];

        if ( $post_id <= 0 ) {
            return $empty;
        }

        $table = $this->table_name();
        if ( ! $this->table_exists( $table ) ) {
            Logs::warn( 'keywords', '[TMW-CAT-KW] keyword candidate table unavailable, skipping resolver', [
                'post_id' => $post_id,
                'table'   => $table,
            ] );
            return $empty;
        }

        $columns     = $this->get_columns( $table );
        $has_volume  = isset( $columns['volume'] );
        $has_status  = isset( $columns['status'] );
        $has_intent  = isset( $columns['intent_type'] );
        $has_entity  = isset( $columns['entity_id'] );

        if ( ! $has_status || ! $has_intent || ! $has_entity ) {
            Logs::warn( 'keywords', '[TMW-CAT-KW] required columns missing in keyword candidate table', [
                'post_id'      => $post_id,
                'has_status'   => $has_status,
                'has_intent'   => $has_intent,
                'has_entity'   => $has_entity,
            ] );
            return $empty;
        }

        $rows = $this->fetch_approved_rows( $table, $post_id, $has_volume );
        if ( empty( $rows ) ) {
            return $empty;
        }

        return $this->process_rows( $rows, $focus_keyword, $rankmath_limit, $content_limit, $post_id );
    }

    // ── Private: DB ──────────────────────────────────────────────────────────

    /** @return string */
    private function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_candidates';
    }

    /** @return bool */
    private function table_exists( string $table ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return is_string( $found ) && strtolower( $found ) === strtolower( $table );
    }

    /**
     * Return column existence map for the given table.
     *
     * @return array<string, bool>
     */
    private function get_columns( string $table ): array {
        if ( isset( self::$columns_cache[ $table ] ) ) {
            return self::$columns_cache[ $table ];
        }
        global $wpdb;
        $columns = [];
        $rows    = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $field = is_array( $row ) ? (string) ( $row['Field'] ?? $row['field'] ?? '' ) : '';
                if ( $field !== '' ) {
                    $columns[ $field ] = true;
                }
            }
        }
        self::$columns_cache[ $table ] = $columns;
        return $columns;
    }

    /**
     * Fetch rows with status='approved', intent_type='category', entity_id=$post_id.
     * Sorted by volume DESC (if column exists), then id ASC for deterministic order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetch_approved_rows( string $table, int $post_id, bool $has_volume ): array {
        global $wpdb;

        $select = $has_volume
            ? 'SELECT id, keyword, status, volume FROM '
            : 'SELECT id, keyword, status, NULL AS volume FROM ';

        $order = $has_volume
            ? 'ORDER BY COALESCE(NULLIF(volume, 0), 0) DESC, id ASC'
            : 'ORDER BY id ASC';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            $select . $table
            . ' WHERE intent_type = %s AND entity_id = %d AND status = %s'
            . ' ' . $order
            . ' LIMIT %d',
            'category',
            $post_id,
            self::SAFE_STATUS,
            self::DB_FETCH_LIMIT
        );
        // phpcs:enable

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    // ── Private: Processing ───────────────────────────────────────────────────

    /**
     * Process fetched rows into rankmath_extras and content_terms buckets.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array{
     *   rankmath_extras: string[],
     *   content_terms: string[],
     *   pool_count: int,
     *   source: string,
     *   skipped: array<int, array{term: string, status: string, reason: string}>,
     * }
     */
    protected function process_rows(
        array  $rows,
        string $focus_keyword,
        int    $rankmath_limit,
        int    $content_limit,
        int    $post_id
    ): array {
        $focus_normalised   = $this->normalise( $focus_keyword );
        $focus_token_key    = $this->token_key( $focus_keyword );
        $accepted           = [];
        $skipped            = [];
        $seen_normalised    = [];
        $seen_token_keys    = [];

        // Pre-seed seen sets with focus keyword so it can never end up in extras.
        if ( $focus_normalised !== '' ) {
            $seen_normalised[ $focus_normalised ] = true;
        }
        if ( $focus_token_key !== '' ) {
            $seen_token_keys[ $focus_token_key ] = true;
        }

        foreach ( $rows as $row ) {
            $raw_keyword = trim( (string) ( $row['keyword'] ?? '' ) );
            if ( $raw_keyword === '' ) {
                continue;
            }

            $row_status = (string) ( $row['status'] ?? '' );

            // Safety double-check: skip anything that is not 'approved'.
            // This should never fire given the SQL WHERE clause, but is a
            // defence-in-depth guard against stale cache or schema surprises.
            if ( $row_status !== self::SAFE_STATUS ) {
                $skipped[] = [
                    'term'   => $raw_keyword,
                    'status' => $row_status,
                    'reason' => 'status_not_approved',
                ];
                Logs::info( 'keywords', '[TMW-CAT-KW] skipped_unsafe_term', [
                    'post_id' => $post_id,
                    'term'    => $raw_keyword,
                    'status'  => $row_status,
                    'reason'  => 'status_not_approved',
                ] );
                continue;
            }

            $norm      = $this->normalise( $raw_keyword );
            $token_key = $this->token_key( $raw_keyword );

            // Skip exact-normalised duplicates.
            if ( isset( $seen_normalised[ $norm ] ) ) {
                $skipped[] = [
                    'term'   => $raw_keyword,
                    'status' => $row_status,
                    'reason' => 'duplicate_normalised',
                ];
                continue;
            }

            // Skip token-reordered duplicates (e.g. "amateur webcam" vs "webcam amateur").
            if ( $token_key !== '' && isset( $seen_token_keys[ $token_key ] ) ) {
                $skipped[] = [
                    'term'   => $raw_keyword,
                    'status' => $row_status,
                    'reason' => 'duplicate_token_reordered',
                ];
                continue;
            }

            $seen_normalised[ $norm ]       = true;
            $seen_token_keys[ $token_key ]  = true;
            $accepted[]                     = $raw_keyword;
        }

        $pool_count      = count( $accepted );
        $rankmath_extras = array_slice( $accepted, 0, $rankmath_limit );
        $content_terms   = array_slice( $accepted, $rankmath_limit, $content_limit );

        return [
            'rankmath_extras' => $rankmath_extras,
            'content_terms'   => $content_terms,
            'pool_count'      => $pool_count,
            'source'          => 'category_db_approved',
            'skipped'         => $skipped,
        ];
    }

    // ── Private: Normalisation helpers ───────────────────────────────────────

    /**
     * Normalise a keyword string for exact-duplicate detection.
     * Lowercases, trims, collapses internal whitespace.
     */
    protected function normalise( string $keyword ): string {
        return trim( (string) preg_replace( '/\s+/u', ' ', strtolower( $keyword ) ) );
    }

    /**
     * Produce a token-sorted key for reorder-duplicate detection.
     * "amateur webcam" and "webcam amateur" → same key.
     *
     * v5.8.31: tokens are also plural-folded (trailing "s" stripped from
     * tokens longer than 3 chars, except "ss" endings), so near-duplicates
     * like "big boob cam" vs "big boob cams" collapse to one slot instead
     * of occupying two of the four Rank Math extra keyword slots.
     */
    protected function token_key( string $keyword ): string {
        $tokens = array_filter( preg_split( '/\s+/u', strtolower( trim( $keyword ) ) ) ?: [] );
        if ( empty( $tokens ) ) {
            return '';
        }
        $tokens = array_map( static function ( string $token ): string {
            if ( strlen( $token ) > 3 && substr( $token, -1 ) === 's' && substr( $token, -2 ) !== 'ss' ) {
                return substr( $token, 0, -1 );
            }
            return $token;
        }, $tokens );
        sort( $tokens );
        return implode( ' ', $tokens );
    }
}
