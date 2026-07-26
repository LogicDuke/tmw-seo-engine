<?php
/**
 * TMW SEO Engine — Keyword Assignment Review Export Service (PR-E).
 *
 * Deterministic JSON and CSV export of review records: fixed column order,
 * fixed record order (already deterministic from the repository listing),
 * no secrets, no environment data. Output paths are restricted to .json and
 * .csv — every other extension (.php in particular) is refused.
 *
 * Log tag: [TMW-KW-ASSIGN-REVIEW]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.24
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentReviewExportService {

    public const LOG_TAG = KeywordAssignmentReviewRepository::LOG_TAG;

    /** Exported columns, in fixed deterministic order. Data columns only — never secrets. */
    public const EXPORT_COLUMNS = [
        'id',
        'review_key',
        'migration_version',
        'keyword_candidate_id',
        'normalized_keyword',
        'classification',
        'candidate_status',
        'planned_action',
        'review_state',
        'execution_state',
        'pool',
        'page_type',
        'target_type',
        'target_id',
        'target_key',
        'target_name',
        'planned_role',
        'planned_status',
        'planned_canonical_owner',
        'active_in_rank_math',
        'present_in_content',
        'source_type',
        'source_reference',
        'source_batch_id',
        'source_import_row_id',
        'snapshot_hash',
        'report_only',
        'reviewer',
        'review_note',
        'reviewed_at',
        'executed_at',
        'execution_result',
        'stale_reason',
        'created_at',
        'updated_at',
    ];

    public const ALLOWED_EXTENSIONS = [ 'json', 'csv' ];

    /** Refuses every extension except .json/.csv (case-insensitive). */
    public function is_safe_output_path( string $path ): bool {
        $extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $extension, self::ALLOWED_EXTENSIONS, true );
    }

    /** Format implied by the output path extension, or '' when unsafe. */
    public function format_for_path( string $path ): string {
        $extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
        return in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ? $extension : '';
    }

    /**
     * @param array<int,array<string,mixed>> $records repository rows
     */
    public function to_json( array $records ): string {
        $rows = array_map( [ $this, 'export_row' ], array_values( $records ) );
        $document = [
            'migration_version' => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'record_count'      => count( $rows ),
            'columns'           => self::EXPORT_COLUMNS,
            'records'           => $rows,
        ];
        $encoded = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) ) {
            throw new \RuntimeException( function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'unknown JSON encoding error' );
        }
        return $encoded;
    }

    /**
     * @param array<int,array<string,mixed>> $records repository rows
     */
    public function to_csv( array $records ): string {
        $handle = fopen( 'php://temp', 'r+' );
        if ( false === $handle ) { return ''; }
        fputcsv( $handle, self::EXPORT_COLUMNS, ',', '"', '\\' );
        foreach ( array_values( $records ) as $record ) {
            $row = $this->export_row( $record );
            fputcsv( $handle, array_map( fn ( $value ) => (string) $value, array_values( $row ) ), ',', '"', '\\' );
        }
        rewind( $handle );
        $csv = (string) stream_get_contents( $handle );
        fclose( $handle );
        return $csv;
    }

    /**
     * One export row: whitelisted columns only, fixed order, normalized
     * scalar values.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function export_row( array $record ): array {
        $row = [];
        foreach ( self::EXPORT_COLUMNS as $column ) {
            $value = $record[ $column ] ?? '';
            if ( in_array( $column, [ 'id', 'keyword_candidate_id', 'target_id', 'planned_canonical_owner', 'active_in_rank_math', 'present_in_content', 'source_batch_id', 'source_import_row_id', 'report_only' ], true ) ) {
                $row[ $column ] = (int) $value;
                continue;
            }
            $row[ $column ] = null === $value ? '' : (string) $value;
        }
        return $row;
    }
}
