<?php
/**
 * TMW SEO Engine — Seed Registry
 *
 * Manages the trusted root seed store (tmwseo_seeds).
 *
 * This class enforces a hard source allowlist. Only explicitly approved source
 * types may write directly to tmwseo_seeds. All generated or discovered phrases
 * must go through ExpansionCandidateRepository (the preview layer) first.
 *
 * Allowed direct-write sources (TRUSTED_DIRECT_SOURCES):
 *   manual           – operator-entered root phrases
 *   model_root       – bare model name only (no suffix phrases)
 *   static_curated   – hardcoded curated phrase set
 *   static           – legacy alias for static_curated; accepted for compat
 *   approved_import  – operator-reviewed CSV/API import
 *   csv_import       – legacy alias for approved_import; accepted for compat
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.3.0 — source allowlist + split API
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SeedRegistry {

    private const REPORT_OPTION = 'tmw_seed_registry_last_report';

    /**
     * Sources that are permitted to write directly into tmwseo_seeds.
     * Everything else is redirected to ExpansionCandidateRepository.
     *
     * @var string[]
     */
    private const TRUSTED_DIRECT_SOURCES = [
        'manual',
        'model_root',
        'static_curated',
        'static',         // legacy alias
        'approved_import',
        'csv_import',     // legacy alias
    ];

    // -----------------------------------------------------------------------
    // Normalisation
    // -----------------------------------------------------------------------

    public static function normalize_seed( string $seed ): string {
        $seed = trim( $seed );
        $seed = preg_replace( '/\s+/', ' ', $seed );
        return mb_strtolower( (string) $seed, 'UTF-8' );
    }

    // -----------------------------------------------------------------------
    // Source allowlist query
    // -----------------------------------------------------------------------

    public static function is_trusted_source( string $source ): bool {
        return in_array( sanitize_key( $source ), self::TRUSTED_DIRECT_SOURCES, true );
    }

    /** @return string[] */
    public static function trusted_sources(): array {
        return self::TRUSTED_DIRECT_SOURCES;
    }

    // -----------------------------------------------------------------------
    // Explicit API: trusted root write
    // -----------------------------------------------------------------------

    /**
     * Write a trusted root seed directly into tmwseo_seeds.
     * Source must be in TRUSTED_DIRECT_SOURCES; otherwise returns false and logs.
     */
    public static function register_trusted_seed(
        string $seed,
        string $source,
        string $entity_type          = 'system',
        int    $entity_id            = 0,
        string $seed_type            = 'general',
        int    $priority             = 1,
        string $import_batch_id      = '',
        string $import_source_label  = ''
    ): bool {
        if ( ! self::is_trusted_source( $source ) ) {
            Logs::warn( 'keywords', '[TMW-SEED] register_trusted_seed blocked: source not in allowlist', [
                'source'    => $source,
                'seed'      => self::normalize_seed( $seed ),
                'caller'    => self::calling_context(),
                'allowlist' => implode( ', ', self::TRUSTED_DIRECT_SOURCES ),
            ] );
            return false;
        }

        return self::write_to_registry(
            $seed,
            $source,
            $entity_type,
            $entity_id,
            $seed_type,
            $priority,
            $import_batch_id,
            $import_source_label
        );
    }

    // -----------------------------------------------------------------------
    // Explicit API: generated phrase → preview layer
    // -----------------------------------------------------------------------

    /**
     * Route a generated or discovered phrase to the preview/review layer.
     * Never writes to tmwseo_seeds directly.
     */
    public static function register_candidate_phrase(
        string $phrase,
        string $source,
        string $generation_rule = '',
        string $entity_type     = 'system',
        int    $entity_id       = 0,
        string $batch_id        = '',
        array  $provenance_meta = []
    ): bool {
        return ExpansionCandidateRepository::insert_candidate(
            $phrase,
            $source,
            $generation_rule,
            $entity_type,
            $entity_id,
            $batch_id,
            $provenance_meta
        );
    }

    // -----------------------------------------------------------------------
    // Backward-compatible register_seed()
    // -----------------------------------------------------------------------

    /**
     * Legacy write path — still supported but now enforces the allowlist.
     *
     * Trusted source  → writes to tmwseo_seeds (unchanged behaviour).
     * Untrusted source → redirected to preview layer + warning log.
     *
     * @deprecated Use register_trusted_seed() or register_candidate_phrase().
     */
    public static function register_seed(
        string $seed,
        string $source,
        string $entity_type = 'system',
        int    $entity_id   = 0,
        string $seed_type   = 'general',
        int    $priority    = 1
    ): bool {
        if ( ! self::is_trusted_source( $source ) ) {
            Logs::warn( 'keywords', '[TMW-SEED] register_seed() called with non-trusted source — redirecting to preview layer', [
                'source' => $source,
                'seed'   => self::normalize_seed( $seed ),
                'caller' => self::calling_context(),
                'note'   => 'Patch caller to use register_candidate_phrase()',
            ] );

            return ExpansionCandidateRepository::insert_candidate(
                $seed,
                $source,
                'legacy_register_seed',
                $entity_type,
                $entity_id,
                '',
                [ 'legacy_fallback' => true ]
            );
        }

        return self::write_to_registry( $seed, $source, $entity_type, $entity_id, $seed_type, $priority );
    }

    // -----------------------------------------------------------------------
    // Batch insert (trusted)
    // -----------------------------------------------------------------------

    /**
     * @param string[] $items
     * @return array{ registered: int, deduplicated: int, blocked: int, source: string }
     */
    public static function register_many(
        array  $items,
        string $source,
        string $entity_type = 'system',
        int    $entity_id   = 0,
        string $seed_type   = 'general',
        int    $priority    = 1
    ): array {
        if ( ! self::is_trusted_source( $source ) ) {
            Logs::warn( 'keywords', '[TMW-SEED] register_many() blocked: non-trusted source — routing items to preview', [
                'source' => $source,
                'count'  => count( $items ),
            ] );

            $blocked = 0;
            foreach ( $items as $item ) {
                ExpansionCandidateRepository::insert_candidate(
                    (string) $item,
                    $source,
                    'legacy_register_many',
                    $entity_type,
                    $entity_id
                );
                $blocked++;
            }

            return [ 'registered' => 0, 'deduplicated' => 0, 'blocked' => $blocked, 'source' => $source ];
        }

        $registered = 0;
        $deduped    = 0;

        foreach ( $items as $item ) {
            $ok = self::write_to_registry( (string) $item, $source, $entity_type, $entity_id, $seed_type, $priority );
            if ( $ok ) {
                $registered++;
            } else {
                $deduped++;
            }
        }

        return [ 'registered' => $registered, 'deduplicated' => $deduped, 'blocked' => 0, 'source' => $source ];
    }

    // -----------------------------------------------------------------------
    // Read API
    // -----------------------------------------------------------------------

    public static function seed_exists( string $seed ): bool {
        global $wpdb;

        $normalised = self::normalize_seed( $seed );
        if ( $normalised === '' ) {
            return false;
        }

        $table = self::table_name();
        $hash  = md5( $normalised );

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s LIMIT 1", $hash )
        );

        return $exists > 0;
    }

    public static function get_seeds_for_discovery( int $limit = 300 ): array {
        global $wpdb;

        $table = self::table_name();
        $cap   = min( 300, max( 1, $limit ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, seed, source, seed_type, priority, entity_type, entity_id, created_at, last_used
                 FROM {$table}
                 ORDER BY priority ASC, COALESCE(last_used,'1970-01-01 00:00:00') ASC, id ASC
                 LIMIT %d",
                $cap
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    public static function mark_seeds_used( array $seed_ids ): void {
        global $wpdb;

        $ids = array_values( array_unique( array_map( 'intval', $seed_ids ) ) );
        $ids = array_values( array_filter( $ids, static fn( $id ) => $id > 0 ) );

        if ( empty( $ids ) ) {
            return;
        }

        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = array_merge( [ current_time( 'mysql' ) ], $ids );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET last_used = %s WHERE id IN ({$placeholders})",
                ...$params
            )
        );
    }

    public static function diagnostics(): array {
        global $wpdb;

        $table  = self::table_name();
        $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $report = get_option( self::REPORT_OPTION, [] );
        if ( ! is_array( $report ) ) {
            $report = [];
        }

        $source_rows = $wpdb->get_results(
            "SELECT source, COUNT(*) AS cnt FROM {$table} GROUP BY source ORDER BY cnt DESC",
            ARRAY_A
        );
        $source_counts_live = [];
        if ( is_array( $source_rows ) ) {
            foreach ( $source_rows as $sr ) {
                $source_counts_live[ (string) $sr['source'] ] = (int) $sr['cnt'];
            }
        }

        $preview_counts = [];
        if ( class_exists( ExpansionCandidateRepository::class ) ) {
            $preview_counts = ExpansionCandidateRepository::count_by_status();
        }

        return [
            'total_seeds'                => $total,
            'seeds_used_this_cycle'      => (int) get_option( 'tmw_seed_registry_last_cycle_used', 0 ),
            'duplicate_prevention_count' => (int) ( $report['duplicates_prevented'] ?? 0 ),
            'seed_sources'               => $source_counts_live,
            'registered_total'           => (int) ( $report['registered_total'] ?? 0 ),
            'trusted_sources'            => self::TRUSTED_DIRECT_SOURCES,
            'preview_queue'              => $preview_counts,
        ];
    }

    // -----------------------------------------------------------------------
    // Internal write (post-allowlist-check only)
    // -----------------------------------------------------------------------

    private static function write_to_registry(
        string $seed,
        string $source,
        string $entity_type,
        int    $entity_id,
        string $seed_type,
        int    $priority,
        string $import_batch_id     = '',
        string $import_source_label = ''
    ): bool {
        global $wpdb;

        $normalised = self::normalize_seed( $seed );
        if ( $normalised === '' ) {
            return false;
        }

        $hash  = md5( $normalised );
        $table = self::table_name();

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s LIMIT 1", $hash )
        );

        if ( $exists > 0 ) {
            self::increment_counter( 'duplicates_prevented', 1 );
            Logs::info( 'keywords', '[TMW-KW] Seed deduplicated by registry', [
                'seed'        => $normalised,
                'source'      => $source,
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
            ] );
            return false;
        }

        $data    = [
            'seed'        => $normalised,
            'source'      => sanitize_key( $source ),
            'seed_type'   => self::sanitize_seed_type( $seed_type ),
            'priority'    => self::sanitize_priority( $priority ),
            'entity_type' => sanitize_key( $entity_type ),
            'entity_id'   => max( 0, $entity_id ),
            'created_at'  => current_time( 'mysql' ),
            'last_used'   => null,
            'hash'        => $hash,
        ];
        $formats = [ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ];

        if ( $import_batch_id !== '' ) {
            $data['import_batch_id']     = $import_batch_id;
            $data['import_source_label'] = sanitize_text_field( $import_source_label );
            $formats[]                   = '%s';
            $formats[]                   = '%s';
        }

        $inserted = $wpdb->insert( $table, $data, $formats );

        if ( $inserted === false ) {
            Logs::warn( 'keywords', '[TMW-KW] Seed registry insert failed', [
                'error' => $wpdb->last_error,
                'seed'  => $normalised,
            ] );
            return false;
        }

        self::increment_source_count( sanitize_key( $source ) );
        self::increment_counter( 'registered_total', 1 );

        return true;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_seeds';
    }

    private static function calling_context(): string {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 4 );
        $parts = [];
        foreach ( array_slice( $trace, 2, 2 ) as $frame ) {
            $file    = isset( $frame['file'] ) ? basename( (string) $frame['file'] ) : '?';
            $line    = (int) ( $frame['line'] ?? 0 );
            $parts[] = $file . ':' . $line;
        }
        return implode( ' → ', $parts );
    }

    private static function sanitize_seed_type( string $seed_type ): string {
        $n = sanitize_key( $seed_type );
        return $n !== '' ? $n : 'general';
    }

    private static function sanitize_priority( int $priority ): int {
        return max( 1, $priority );
    }

    private static function increment_source_count( string $source ): void {
        $report = get_option( self::REPORT_OPTION, [] );
        if ( ! is_array( $report ) ) {
            $report = [];
        }
        if ( ! isset( $report['source_counts'] ) || ! is_array( $report['source_counts'] ) ) {
            $report['source_counts'] = [];
        }
        $report['source_counts'][ $source ] = (int) ( $report['source_counts'][ $source ] ?? 0 ) + 1;
        update_option( self::REPORT_OPTION, $report, false );
    }

    private static function increment_counter( string $key, int $by = 1 ): void {
        $report = get_option( self::REPORT_OPTION, [] );
        if ( ! is_array( $report ) ) {
            $report = [];
        }
        $report[ $key ] = (int) ( $report[ $key ] ?? 0 ) + $by;
        update_option( self::REPORT_OPTION, $report, false );
    }
}
