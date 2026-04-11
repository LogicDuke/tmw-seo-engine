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
     *
     * model_root ambiguity gate (v5.3.2):
     *   MULTI-TOKEN model names (e.g. "anna claire", "mia malkova") are unambiguous
     *   and are allowed through as trusted roots.
     *   SINGLE-TOKEN model names (e.g. "arianna", "bella", "sophia") are common
     *   first names that collide with many off-niche queries — they are blocked from
     *   the trusted seed layer to prevent junk root propagation.
     *   Manual seeds, static_curated, approved_import, and all other source types
     *   are NOT affected by this gate.
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

        // model_root ambiguity gate — only applies to model_root source.
        if ( $source === 'model_root' && ! self::should_allow_model_root_seed( $seed ) ) {
            Logs::info( 'keywords', '[TMW-SEED] model_root seed blocked: single-token model name is ambiguous', [
                'seed'        => self::normalize_seed( $seed ),
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'note'        => 'Anchored phrase variants still enter the preview layer via ExpansionCandidateRepository.',
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
    // model_root ambiguity helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true when a model_root seed may be written to tmwseo_seeds.
     * Multi-token names are unambiguous; single-token names are blocked.
     */
    private static function should_allow_model_root_seed( string $seed ): bool {
        return self::is_multi_token_seed( self::normalize_seed( $seed ) );
    }

    /**
     * Returns true when the normalized seed contains more than one whitespace-
     * separated token.  "anna claire" → true.  "arianna" → false.
     */
    private static function is_multi_token_seed( string $normalized_seed ): bool {
        return str_word_count( trim( $normalized_seed ) ) > 1;
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

        // ── Phase 2: ROI-aware seed selection.
        //    1. Skip seeds in active cooldown (cooldown_until > NOW).
        //    2. Skip auto-retired seeds (consecutive_zero_yield >= 5) unless manual.
        //    3. Prefer never-expanded seeds first.
        //    4. Then prefer high-ROI seeds.
        //    5. Then longest-resting seeds.
        //
        //    The new columns are added by the seed-roi-columns migration.
        //    If the columns don't exist yet (pre-migration), fall back to the
        //    original query gracefully.
        $has_roi_columns = self::has_roi_columns();

        if ( $has_roi_columns ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, seed, source, seed_type, priority, entity_type, entity_id, created_at, last_used,
                            last_expanded_at, expansion_count, net_new_yielded, duplicates_returned,
                            estimated_spend_usd, roi_score, consecutive_zero_yield, cooldown_until
                     FROM {$table}
                     WHERE ( cooldown_until IS NULL OR cooldown_until <= %s )
                       AND ( consecutive_zero_yield < 5 OR source = 'manual' )
                     ORDER BY
                        CASE WHEN last_expanded_at IS NULL THEN 0 ELSE 1 END ASC,
                        roi_score DESC,
                        COALESCE(last_expanded_at, '1970-01-01 00:00:00') ASC,
                        priority ASC,
                        id ASC
                     LIMIT %d",
                    current_time( 'mysql' ),
                    $cap
                ),
                ARRAY_A
            );
        } else {
            // Pre-migration fallback: original query.
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
        }

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
            'discovery_enabled'          => (bool) get_option( 'tmw_discovery_enabled', 1 ),
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
    // Seed ROI tracking (Phase 2)
    // -----------------------------------------------------------------------

    /**
     * Record the outcome of expanding a seed. Updates ROI columns and
     * sets the cooldown_until timestamp.
     *
     * @param string $seed_phrase Normalized seed phrase (not ID — the engine
     *                            works with phrases, not registry row IDs).
     * @param array  $result      Keys: net_new (int), duplicates (int),
     *                            provider (string), estimated_cost (float).
     */
    public static function record_expansion_result( string $seed_phrase, array $result ): void {
        global $wpdb;

        if ( ! self::has_roi_columns() ) {
            return; // Migration hasn't run yet — silently skip.
        }

        $normalised = self::normalize_seed( $seed_phrase );
        if ( $normalised === '' ) {
            return;
        }

        $table    = self::table_name();
        $hash     = md5( $normalised );
        $net_new  = max( 0, (int) ( $result['net_new'] ?? 0 ) );
        $dupes    = max( 0, (int) ( $result['duplicates'] ?? 0 ) );
        $provider = sanitize_key( (string) ( $result['provider'] ?? 'unknown' ) );
        $cost     = max( 0.0, (float) ( $result['estimated_cost'] ?? 0.0 ) );

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, expansion_count, net_new_yielded, duplicates_returned, estimated_spend_usd, consecutive_zero_yield FROM {$table} WHERE hash = %s LIMIT 1", $hash ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return; // Seed not in registry.
        }

        $expansion_count       = (int) ( $row['expansion_count'] ?? 0 ) + 1;
        $total_net_new         = (int) ( $row['net_new_yielded'] ?? 0 ) + $net_new;
        $total_dupes           = (int) ( $row['duplicates_returned'] ?? 0 ) + $dupes;
        $total_spend           = (float) ( $row['estimated_spend_usd'] ?? 0 ) + $cost;
        $consecutive_zero      = $net_new > 0 ? 0 : ( (int) ( $row['consecutive_zero_yield'] ?? 0 ) + 1 );

        // ROI score: ratio of net-new keywords to total expansions (0–100 scale).
        $roi_score = $expansion_count > 0 ? round( ( $total_net_new / $expansion_count ) * 10, 2 ) : 0;

        // Cooldown: base 30 days, escalating with consecutive zero-yield runs.
        $base_cooldown_days = (int) \TMWSEO\Engine\Services\Settings::get( 'seed_expansion_cooldown_days', 30 );
        if ( $consecutive_zero >= 5 ) {
            $cooldown_days = $base_cooldown_days * 6; // ~180 days — effectively retired
        } elseif ( $consecutive_zero >= 3 ) {
            $cooldown_days = $base_cooldown_days * 3;
        } elseif ( $consecutive_zero >= 1 ) {
            $cooldown_days = $base_cooldown_days * 2;
        } else {
            $cooldown_days = $base_cooldown_days;
        }

        $cooldown_until = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $cooldown_days ) );

        $wpdb->update(
            $table,
            [
                'last_expanded_at'       => current_time( 'mysql' ),
                'expansion_count'        => $expansion_count,
                'net_new_yielded'        => $total_net_new,
                'duplicates_returned'    => $total_dupes,
                'estimated_spend_usd'    => round( $total_spend, 4 ),
                'last_provider'          => $provider,
                'cooldown_until'         => $cooldown_until,
                'roi_score'              => $roi_score,
                'consecutive_zero_yield' => $consecutive_zero,
            ],
            [ 'hash' => $hash ],
            [ '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%f', '%d' ],
            [ '%s' ]
        );
    }

    /**
     * Hard-retire seeds that have proven unproductive.
     * Sets cooldown_until to 180 days for any seed with
     * consecutive_zero_yield >= $threshold.
     *
     * @return int Number of seeds retired.
     */
    public static function retire_exhausted_seeds( int $threshold = 5 ): int {
        global $wpdb;

        if ( ! self::has_roi_columns() ) {
            return 0;
        }

        $table  = self::table_name();
        $far    = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * 180 ) );

        $affected = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET cooldown_until = %s
             WHERE consecutive_zero_yield >= %d
               AND source != 'manual'
               AND ( cooldown_until IS NULL OR cooldown_until < %s )",
            $far,
            max( 1, $threshold ),
            $far
        ) );

        if ( $affected > 0 ) {
            Logs::info( 'keywords', '[TMW-SEED] Auto-retired exhausted seeds', [
                'retired'   => $affected,
                'threshold' => $threshold,
            ] );
        }

        return $affected;
    }

    /**
     * Check whether the ROI tracking columns exist on the seeds table.
     * Result is cached per-request to avoid repeated SHOW COLUMNS queries.
     */
    private static function has_roi_columns(): bool {
        static $result = null;
        if ( $result !== null ) {
            return $result;
        }

        global $wpdb;
        $table  = self::table_name();
        $column = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'roi_score' LIMIT 1",
            $table
        ) );

        $result = ( $column === 'roi_score' );
        return $result;
    }

    // -----------------------------------------------------------------------
    // Seed Reset & Starter Pack (admin panel)
    // -----------------------------------------------------------------------

    /**
     * Delete all seeds matching the given source types.
     *
     * Used by the Reset & Starter Pack admin panel. This is a destructive,
     * irreversible operation — the admin page must enforce confirmation UX.
     *
     * @param string[] $sources  e.g. ['csv_import', 'approved_import', 'static_curated']
     * @return int  Number of rows deleted.
     */
    public static function purge_by_sources( array $sources ): int {
        global $wpdb;

        $table = self::table_name();
        $clean = array_values( array_unique( array_filter( array_map( 'sanitize_key', $sources ) ) ) );

        if ( empty( $clean ) ) {
            return 0;
        }

        // Safety: only allow purging known source types.
        $allowed = [ 'csv_import', 'approved_import', 'static_curated', 'static', 'manual', 'model_root' ];
        $clean   = array_values( array_intersect( $clean, $allowed ) );

        if ( empty( $clean ) ) {
            Logs::warn( 'seed_reset', '[TMW-SEED] purge_by_sources called with no valid sources', [
                'requested' => $sources,
            ] );
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $clean ), '%s' ) );

        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE source IN ({$placeholders})",
                ...$clean
            )
        );

        Logs::info( 'seed_reset', '[TMW-SEED] Purge by sources completed', [
            'sources' => $clean,
            'deleted' => $deleted,
            'user'    => get_current_user_id(),
        ] );

        return $deleted;
    }

    /**
     * Returns the recommended starter seed pack.
     *
     * ============================================================
     * TRUSTED ROOT LAYER — BROAD COMMERCIAL ROOTS ONLY
     * ============================================================
     *
     * These are deliberately minimal, clean, broad, commercial-intent
     * root phrases for an adult webcam site. They are registered as
     * source=static_curated into tmwseo_seeds during architecture reset.
     *
     * PHILOSOPHY (locked):
     *   - Broad and category-first only.
     *   - No descriptor-heavy phrases (no "live asian cams", "blonde cam
     *     girls", etc.) — those belong in the builder/candidate layer.
     *   - No platform or competitor names.
     *   - No niche taxonomy phrases.
     *
     * Descriptor / niche candidate phrases live in:
     *   data/niche-pattern-families.php
     *   Generated by CuratedKeywordLibrary::generate_builder_candidates()
     *   Routed through ExpansionCandidateRepository (preview/review).
     *
     * DO NOT expand this list with niche or descriptor phrases.
     * DO NOT populate this from curated-seeds.php or category-seed-patterns.php.
     *
     * @return string[]
     */
    public static function get_starter_pack(): array {
        return [
            'adult cam',
            'adult cams',
            'adult web cam',
            'adult live cam',
            'live adult cam',
            'adult sex cam',
            'adult sex cams',
            'adult cam site',
            'adult cam sites',
            'live cam adult',
        ];
    }

    /**
     * Tag starter pack seeds with provenance metadata (batch ID + label).
     *
     * Called after register_many() to add import provenance. This is a
     * post-insert UPDATE because register_many() doesn't accept batch params.
     * Only works if provenance columns exist. Silent no-op otherwise.
     *
     * @param string[] $seeds  The starter pack phrases (normalized internally).
     */
    public static function tag_starter_pack_provenance( array $seeds ): void {
        global $wpdb;

        $table = self::table_name();

        // Check provenance columns exist (added in 4.3.0 migration).
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'import_batch_id' LIMIT 1",
            $table
        ) );

        if ( $col !== 'import_batch_id' ) {
            return;
        }

        foreach ( $seeds as $seed ) {
            $normalised = self::normalize_seed( $seed );
            if ( $normalised === '' ) {
                continue;
            }
            $hash = md5( $normalised );
            $wpdb->update(
                $table,
                [
                    'import_batch_id'     => 'starter_pack_v1',
                    'import_source_label' => 'Recommended Starter Pack v1',
                ],
                [ 'hash' => $hash ],
                [ '%s', '%s' ],
                [ '%s' ]
            );
        }
    }

    /**
     * Returns live seed counts grouped by source type.
     *
     * Used by the Reset tab to show exactly what will be deleted.
     *
     * @return array<string, int>  e.g. ['manual' => 12, 'csv_import' => 847]
     */
    public static function get_source_counts(): array {
        global $wpdb;

        $table = self::table_name();
        $rows  = $wpdb->get_results(
            "SELECT source, COUNT(*) AS cnt FROM {$table} GROUP BY source ORDER BY cnt DESC",
            ARRAY_A
        );

        $counts = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $counts[ (string) $row['source'] ] = (int) $row['cnt'];
            }
        }

        return $counts;
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
