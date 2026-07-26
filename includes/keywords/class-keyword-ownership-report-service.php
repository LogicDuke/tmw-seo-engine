<?php
/**
 * TMW SEO Engine — Keyword Ownership Report Service (PR-A).
 *
 * READ-ONLY global keyword-ownership diagnostic. For every normalized keyword
 * candidate across ALL pools, targets, and page types it reports identity,
 * ownership, import-row lineage, candidate_id sharing, Rank Math presence,
 * content presence, staleness, duplicates, cross-pool collisions, and the
 * three parallel registries (post-meta ownership, keyword usage tables,
 * cannibalization flags).
 *
 * SAFETY CONTRACT: this class performs SELECT / SHOW queries only. It never
 * invokes any mutating wpdb method and never touches options, meta,
 * transients, caches, Rank Math data, content, approval state, or schema.
 * `KeywordPoolCandidateRepository` is instantiated strictly for its public
 * `normalize_keyword()` helper; no persistence path of that class is invoked.
 *
 * Only {$prefix}tmw_keyword_candidates is REQUIRED. Every other table is
 * optional and its absence is reported (`optional_tables_missing`) instead of
 * failing: tmw_keyword_import_batches, tmw_keyword_import_rows,
 * tmw_cannibalization_flags, tmwseo_keyword_usage, tmwseo_keyword_usage_log.
 *
 * Log tag: [TMW-KW-OWNERSHIP-REPORT]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.20
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordOwnershipReportService {

    public const LOG_TAG = '[TMW-KW-OWNERSHIP-REPORT]';

    /** Candidate rows fetched per keyset-pagination page. */
    private const CHUNK_SIZE = 500;

    /** Bounded page cache size (post_id => page bundle). */
    private const PAGE_CACHE_LIMIT = 2000;

    /** Resolution states ordered most-severe first (index = severity rank). */
    private const STATE_SEVERITY = [
        'cross_pool_conflict',
        'shared_candidate_id',
        'blocked_different_target',
        'rankmath_active_content_missing',
        'stale_owner',
        'approved_unused',
        'owner_active',
        'sole_owner_active',
        'unassigned',
    ];

    private KeywordPoolCandidateRepository $normalizer;

    /** @var array<string,int> */
    private array $summary_counters = [];

    /** @var array<int,string> */
    private array $missing_optional_tables = [];

    /** @var array<string,array{count:int,hash:string}> cluster_key => info */
    private array $cluster_map = [];

    /** @var array<int,array<string,mixed>> post_id => page bundle */
    private array $page_cache = [];

    /** @var array<string,array<string,bool>> table => column map */
    private array $columns_cache = [];

    private bool $ran = false;

    public function __construct( ?KeywordPoolCandidateRepository $normalizer = null ) {
        $this->normalizer = $normalizer ?: new KeywordPoolCandidateRepository();
        $this->reset_summary();
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Stream the full ownership report.
     *
     * Summary counters always reflect the FULL dataset; the $filters array
     * only controls which rows are yielded.
     *
     * Recognized filter keys: keyword (string), candidate_id (int),
     * target_id (int), pool (string), conflicts_only, shared_candidate_ids_only,
     * approved_unused_only, rankmath_unsupported_only, duplicates_only (bool).
     *
     * @param array<string,mixed> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function run( array $filters = [] ): \Generator {
        $this->reset_summary();
        $this->missing_optional_tables = [];
        $this->page_cache              = [];
        $this->ran                     = true;

        if ( ! $this->table_exists( $this->candidates_table() ) ) {
            $this->log( 'candidates table missing — aborting report' );
            $this->summary_counters['candidates_table_missing'] = 1;
            return;
        }
        foreach ( [ $this->batches_table(), $this->rows_table(), $this->cannibalization_table(), $this->usage_table() ] as $optional ) {
            if ( ! $this->table_exists( $optional ) ) {
                $this->missing_optional_tables[] = $optional;
            }
        }
        if ( [] !== $this->missing_optional_tables ) {
            $this->log( 'optional tables missing: ' . implode( ',', $this->missing_optional_tables ) );
        }

        $this->build_cluster_map();

        $normalized_keyword_filter = '';
        if ( '' !== trim( (string) ( $filters['keyword'] ?? '' ) ) ) {
            $normalized_keyword_filter = $this->normalizer->normalize_keyword( (string) $filters['keyword'] );
        }

        $after_id = 0;
        do {
            $candidates = $this->fetch_candidates_chunk( $after_id, self::CHUNK_SIZE );
            if ( [] === $candidates ) {
                break;
            }
            $after_id = (int) end( $candidates )['id'];

            $chunk_rows = $this->assemble_chunk( $candidates );

            foreach ( $chunk_rows as $row ) {
                $this->accumulate_summary( $row );
                if ( $this->row_passes_filters( $row, $filters, $normalized_keyword_filter ) ) {
                    yield $row;
                }
            }
        } while ( count( $candidates ) === self::CHUNK_SIZE );

        $this->log( 'run complete; identities=' . $this->summary_counters['total_candidate_identities'] );
    }

    /** @return array<string,mixed> Full-dataset totals (see spec §7). */
    public function summary(): array {
        $summary = $this->summary_counters;
        $summary['near_duplicate_clusters'] = $this->count_near_duplicate_clusters();
        $summary['optional_tables_missing'] = [] === $this->missing_optional_tables
            ? 'none'
            : implode( ',', $this->missing_optional_tables );
        $summary['ran'] = $this->ran;
        return $summary;
    }

    /**
     * Map one assembled report row to its single most-severe verdict.
     *
     * @param array<string,mixed> $row
     */
    public function resolution_state( array $row ): string {
        $flags = [
            'cross_pool_conflict'             => ! empty( $row['cross_pool_collision'] ),
            'shared_candidate_id'             => ! empty( $row['candidate_id_shared_across_targets'] ) || ! empty( $row['candidate_id_shared_across_batches'] ),
            'blocked_different_target'        => ! empty( $row['blocked_different_target_history'] ),
            'rankmath_active_content_missing' => ! empty( $row['active_but_unsupported'] ),
            'stale_owner'                     => ! empty( $row['stale_owner'] ),
            'approved_unused'                 => ! empty( $row['approved_but_unused'] ),
        ];
        foreach ( self::STATE_SEVERITY as $state ) {
            if ( ! empty( $flags[ $state ] ) ) {
                return $state;
            }
        }
        $has_owner = '' !== (string) ( $row['target_type'] ?? '' ) || (int) ( $row['target_id'] ?? 0 ) > 0 || (int) ( $row['entity_id'] ?? 0 ) > 0;
        if ( ! $has_owner ) {
            return 'unassigned';
        }
        $target_count = is_array( $row['distinct_targets'] ?? null ) ? count( $row['distinct_targets'] ) : 0;
        return $target_count <= 1 ? 'sole_owner_active' : 'owner_active';
    }

    // ── Chunk assembly ────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function assemble_chunk( array $candidates ): array {
        $candidate_ids = [];
        $keywords      = [];
        foreach ( $candidates as $candidate ) {
            $candidate_ids[] = (int) $candidate['id'];
            $normalized      = $this->normalizer->normalize_keyword( (string) ( $candidate['keyword'] ?? '' ) );
            $keywords[]      = $normalized;
        }
        $keywords = array_values( array_unique( array_filter( $keywords, 'strlen' ) ) );

        $rows_by_candidate = $this->index_rows( $this->fetch_import_rows_by_candidate_ids( $candidate_ids ), 'candidate_id' );
        $rows_by_keyword   = $this->index_rows_by_keyword( $this->fetch_import_rows_by_keywords( $keywords ) );

        $batch_ids = [];
        foreach ( [ $rows_by_candidate, $rows_by_keyword ] as $indexed ) {
            foreach ( $indexed as $rows ) {
                foreach ( $rows as $row ) {
                    $bid = (int) ( $row['batch_id'] ?? 0 );
                    if ( $bid > 0 ) { $batch_ids[ $bid ] = true; }
                }
            }
        }
        $batches = $this->index_rows( $this->fetch_batches( array_keys( $batch_ids ) ), 'id' );

        $postmeta_ownership = $this->fetch_postmeta_ownership( $keywords );
        $usage_rows         = $this->fetch_usage_rows( $keywords );
        $cannibal_rows      = $this->fetch_cannibalization_rows( $keywords );

        $report_rows = [];
        foreach ( $candidates as $candidate ) {
            $report_rows[] = $this->assemble_row(
                $candidate,
                $rows_by_candidate[ (int) $candidate['id'] ] ?? [],
                $rows_by_keyword,
                $batches,
                $postmeta_ownership,
                $usage_rows,
                $cannibal_rows
            );
        }
        return $report_rows;
    }

    /**
     * @param array<string,mixed>                      $candidate
     * @param array<int,array<string,mixed>>           $candidate_linked_rows
     * @param array<string,array<int,array<string,mixed>>> $rows_by_keyword
     * @param array<int|string,array<int,array<string,mixed>>> $batches indexed by id (single-row lists)
     * @param array<string,array<int,array<string,mixed>>> $postmeta_ownership normalized_keyword => entries
     * @param array<string,array<int,array<string,mixed>>> $usage_rows
     * @param array<string,array<int,array<string,mixed>>> $cannibal_rows
     * @return array<string,mixed>
     */
    private function assemble_row( array $candidate, array $candidate_linked_rows, array $rows_by_keyword, array $batches, array $postmeta_ownership, array $usage_rows, array $cannibal_rows ): array {
        $candidate_id = (int) ( $candidate['id'] ?? 0 );
        $normalized   = $this->normalizer->normalize_keyword( (string) ( $candidate['keyword'] ?? '' ) );

        // Merge both linkages, labelled, de-duplicated by import-row id.
        $import_rows = [];
        foreach ( $candidate_linked_rows as $row ) {
            $import_rows[ (int) $row['id'] ] = [ 'match' => 'by_candidate_id', 'row' => $row ];
        }
        foreach ( $rows_by_keyword[ $normalized ] ?? [] as $row ) {
            $rid = (int) $row['id'];
            if ( ! isset( $import_rows[ $rid ] ) ) {
                $import_rows[ $rid ] = [ 'match' => 'by_keyword', 'row' => $row ];
            }
        }

        $import_row_reports = [];
        $seen_batch_ids     = [];
        $shared_batch_ids   = [];
        $shared_targets     = [];
        $batch_pools        = [];
        $distinct_targets   = [];
        $page_ids           = [];
        $blocked_history    = false;
        $per_batch_keyword_rows = [];

        // Candidate's own owner columns contribute to targets/pages first.
        $own_target_type = (string) ( $candidate['target_type'] ?? '' );
        $own_target_id   = (int) ( $candidate['target_id'] ?? 0 );
        if ( '' !== $own_target_type || $own_target_id > 0 ) {
            $key = $own_target_type . ':' . $own_target_id;
            $distinct_targets[ $key ] = [
                'target_type' => $own_target_type,
                'target_id'   => $own_target_id,
                'target_name' => (string) ( $candidate['target_name'] ?? '' ),
                'source'      => 'candidate_row',
            ];
            if ( 'global' !== $own_target_type && $own_target_id > 0 ) {
                $page_ids[ $own_target_id ] = true;
            }
        }
        $entity_id = (int) ( $candidate['entity_id'] ?? 0 );
        if ( $entity_id > 0 ) {
            $page_ids[ $entity_id ] = true;
        }

        foreach ( $import_rows as $rid => $entry ) {
            $row      = $entry['row'];
            $batch_id = (int) ( $row['batch_id'] ?? 0 );
            $batch    = $batches[ $batch_id ][0] ?? [];
            $pool     = (string) ( $batch['pool'] ?? '' );
            $bt_type  = (string) ( $batch['target_type'] ?? $row['target_type'] ?? '' );
            $bt_id    = (int) ( $batch['target_id'] ?? $row['target_id'] ?? 0 );
            $bt_name  = (string) ( $batch['target_name'] ?? $row['target_name'] ?? '' );

            $import_row_reports[] = [
                'row_id'            => $rid,
                'match'             => $entry['match'],
                'batch_id'          => $batch_id,
                'import_batch_id'   => (string) ( $row['import_batch_id'] ?? '' ),
                'pool'              => $pool,
                'batch_target_type' => $bt_type,
                'batch_target_id'   => $bt_id,
                'batch_target_name' => $bt_name,
                'row_status'        => (string) ( $row['status'] ?? '' ),
                'result_action'     => (string) ( $row['result_action'] ?? '' ),
                'result_reason'     => (string) ( $row['result_reason'] ?? '' ),
            ];

            if ( '' !== $pool ) { $batch_pools[ $pool ] = true; }
            if ( '' !== $bt_type || $bt_id > 0 ) {
                $tkey = $bt_type . ':' . $bt_id;
                if ( ! isset( $distinct_targets[ $tkey ] ) ) {
                    $distinct_targets[ $tkey ] = [
                        'target_type' => $bt_type,
                        'target_id'   => $bt_id,
                        'target_name' => $bt_name,
                        'source'      => 'import_batch',
                    ];
                }
                if ( 'global' !== $bt_type && $bt_id > 0 ) {
                    $page_ids[ $bt_id ] = true;
                }
            }
            if ( false !== strpos( (string) ( $row['result_reason'] ?? '' ), 'existing_keyword_has_different_target' ) ) {
                $blocked_history = true;
            }
            if ( 'by_candidate_id' === $entry['match'] && $batch_id > 0 ) {
                $shared_batch_ids[ $batch_id ] = true;
                if ( '' !== $bt_type || $bt_id > 0 ) {
                    $shared_targets[ $bt_type . ':' . $bt_id ] = true;
                }
            }
            if ( $batch_id > 0 ) {
                $seen_batch_ids[ $batch_id ] = true;
                $per_batch_keyword_rows[ $batch_id ][] = $rid;
            }
        }

        $duplicate_same_batch = false;
        foreach ( $per_batch_keyword_rows as $rids ) {
            if ( count( $rids ) >= 2 ) { $duplicate_same_batch = true; break; }
        }
        $duplicate_cross_batch = count( $seen_batch_ids ) >= 2;

        $intent_type = (string) ( $candidate['intent_type'] ?? '' );
        $cross_pool  = count( $batch_pools ) >= 2
            || ( '' !== $intent_type && [] !== $batch_pools && ! isset( $batch_pools[ $intent_type ] ) );

        // Page-level Rank Math / content presence.
        $pages          = $this->load_pages( array_keys( $page_ids ) );
        $rankmath       = [];
        $content        = [];
        $page_types     = [];
        $unresolvable   = [];
        $in_rankmath    = false;
        $in_content     = false;
        $active_unsupported = false;
        foreach ( array_keys( $page_ids ) as $pid ) {
            $page = $pages[ $pid ] ?? null;
            if ( null === $page ) {
                $unresolvable[] = $pid;
                continue;
            }
            $page_types[ (string) $page['post_type'] ] = true;
            $role         = $this->rankmath_role( $normalized, (string) $page['rankmath_csv'] );
            $has_content  = $this->content_contains( $normalized, (string) $page['content_normalized'] );
            $rankmath[]   = [ 'post_id' => $pid, 'post_type' => (string) $page['post_type'], 'rankmath_role' => $role ];
            $content[]    = [ 'post_id' => $pid, 'present' => $has_content ];
            if ( 'absent' !== $role ) {
                $in_rankmath = true;
                if ( ! $has_content ) { $active_unsupported = true; }
            }
            if ( $has_content ) { $in_content = true; }
        }

        $status            = (string) ( $candidate['status'] ?? '' );
        $approved_unused   = 'approved' === $status && ! $in_rankmath && ! $in_content;
        $has_owner_columns = '' !== $own_target_type || $own_target_id > 0 || $entity_id > 0;
        $stale_owner       = 'approved' === $status && $has_owner_columns && ( $active_unsupported || $approved_unused );

        $cluster_key = $this->cluster_key( (string) ( $candidate['keyword'] ?? '' ), (string) ( $candidate['canonical'] ?? '' ) );
        $cluster     = $this->cluster_map[ $cluster_key ] ?? null;
        $cluster_id  = ( null !== $cluster && $cluster['count'] >= 2 ) ? $cluster['hash'] : '';

        $row = [
            'candidate_id'                       => $candidate_id,
            'keyword'                            => (string) ( $candidate['keyword'] ?? '' ),
            'canonical'                          => (string) ( $candidate['canonical'] ?? '' ),
            'normalized_keyword'                 => $normalized,
            'status'                             => $status,
            'intent_type'                        => $intent_type,
            'entity_type'                        => (string) ( $candidate['entity_type'] ?? '' ),
            'entity_id'                          => $entity_id,
            'target_type'                        => $own_target_type,
            'target_id'                          => $own_target_id,
            'target_name'                        => (string) ( $candidate['target_name'] ?? '' ),
            'target_slug'                        => (string) ( $candidate['target_slug'] ?? '' ),
            'import_rows'                        => $import_row_reports,
            'distinct_targets'                   => array_values( $distinct_targets ),
            'distinct_page_types'                => array_keys( $page_types ),
            'candidate_id_shared_across_batches' => count( $shared_batch_ids ) >= 2,
            'candidate_id_shared_across_targets' => count( $shared_targets ) >= 2,
            'rankmath_presence'                  => $rankmath,
            'content_presence'                   => $content,
            'target_unresolvable'                => $unresolvable,
            'active_but_unsupported'             => $active_unsupported,
            'approved_but_unused'                => $approved_unused,
            'stale_owner'                        => $stale_owner,
            'blocked_different_target_history'   => $blocked_history,
            'cross_pool_collision'               => $cross_pool,
            'duplicate_rows_same_batch'          => $duplicate_same_batch,
            'duplicate_rows_cross_batch'         => $duplicate_cross_batch,
            'near_duplicate_cluster_id'          => $cluster_id,
            'postmeta_ownership'                 => $this->registry_value( $postmeta_ownership, $normalized, '' ),
            'usage_registry'                     => $this->registry_value( $usage_rows, $normalized, $this->usage_table() ),
            'cannibalization_flags'              => $this->registry_value( $cannibal_rows, $normalized, $this->cannibalization_table() ),
        ];
        $row['resolution_state'] = $this->resolution_state( $row );
        return $row;
    }

    // ── Filters and summary ───────────────────────────────────────────────

    /** @param array<string,mixed> $row @param array<string,mixed> $filters */
    private function row_passes_filters( array $row, array $filters, string $normalized_keyword_filter ): bool {
        if ( '' !== $normalized_keyword_filter && $row['normalized_keyword'] !== $normalized_keyword_filter ) {
            return false;
        }
        if ( (int) ( $filters['candidate_id'] ?? 0 ) > 0 && (int) $row['candidate_id'] !== (int) $filters['candidate_id'] ) {
            return false;
        }
        $target_filter = (int) ( $filters['target_id'] ?? 0 );
        if ( $target_filter > 0 ) {
            $hit = false;
            foreach ( (array) $row['distinct_targets'] as $target ) {
                if ( (int) ( $target['target_id'] ?? 0 ) === $target_filter ) { $hit = true; break; }
            }
            if ( ! $hit ) { return false; }
        }
        $pool_filter = strtolower( trim( (string) ( $filters['pool'] ?? '' ) ) );
        if ( '' !== $pool_filter ) {
            $pools = [ strtolower( (string) $row['intent_type'] ) => true ];
            foreach ( (array) $row['import_rows'] as $import_row ) {
                $pools[ strtolower( (string) ( $import_row['pool'] ?? '' ) ) ] = true;
            }
            if ( ! isset( $pools[ $pool_filter ] ) ) { return false; }
        }
        if ( ! empty( $filters['conflicts_only'] )
            && empty( $row['blocked_different_target_history'] )
            && empty( $row['cross_pool_collision'] )
            && empty( $row['candidate_id_shared_across_targets'] ) ) {
            return false;
        }
        if ( ! empty( $filters['shared_candidate_ids_only'] )
            && empty( $row['candidate_id_shared_across_batches'] )
            && empty( $row['candidate_id_shared_across_targets'] ) ) {
            return false;
        }
        if ( ! empty( $filters['approved_unused_only'] ) && empty( $row['approved_but_unused'] ) ) {
            return false;
        }
        if ( ! empty( $filters['rankmath_unsupported_only'] ) && empty( $row['active_but_unsupported'] ) ) {
            return false;
        }
        if ( ! empty( $filters['duplicates_only'] )
            && empty( $row['duplicate_rows_same_batch'] )
            && empty( $row['duplicate_rows_cross_batch'] )
            && '' === (string) $row['near_duplicate_cluster_id'] ) {
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private function accumulate_summary( array $row ): void {
        $this->summary_counters['total_candidate_identities']++;
        if ( 'approved' === $row['status'] )                        { $this->summary_counters['approved_candidates']++; }
        if ( count( (array) $row['distinct_targets'] ) >= 2 )       { $this->summary_counters['candidates_referenced_by_multiple_targets']++; }
        if ( ! empty( $row['candidate_id_shared_across_batches'] ) ){ $this->summary_counters['shared_candidate_ids_across_batches']++; }
        if ( ! empty( $row['approved_but_unused'] ) )               { $this->summary_counters['approved_but_unused']++; }
        if ( ! empty( $row['active_but_unsupported'] ) )            { $this->summary_counters['rankmath_active_content_missing']++; }
        if ( ! empty( $row['blocked_different_target_history'] ) )  { $this->summary_counters['blocked_due_to_different_target']++; }
        if ( ! empty( $row['cross_pool_collision'] ) )              { $this->summary_counters['cross_pool_conflicts']++; }
        if ( ! empty( $row['duplicate_rows_same_batch'] ) )         { $this->summary_counters['duplicate_import_rows_same_batch']++; }
        if ( ! empty( $row['duplicate_rows_cross_batch'] ) )        { $this->summary_counters['duplicate_import_rows_cross_batch']++; }
        if ( ! empty( $row['stale_owner'] ) )                       { $this->summary_counters['stale_owners']++; }
    }

    private function reset_summary(): void {
        $this->summary_counters = [
            'total_candidate_identities'               => 0,
            'approved_candidates'                      => 0,
            'candidates_referenced_by_multiple_targets'=> 0,
            'shared_candidate_ids_across_batches'      => 0,
            'approved_but_unused'                      => 0,
            'rankmath_active_content_missing'          => 0,
            'blocked_due_to_different_target'          => 0,
            'cross_pool_conflicts'                     => 0,
            'duplicate_import_rows_same_batch'         => 0,
            'duplicate_import_rows_cross_batch'        => 0,
            'stale_owners'                             => 0,
        ];
    }

    private function count_near_duplicate_clusters(): int {
        $count = 0;
        foreach ( $this->cluster_map as $cluster ) {
            if ( $cluster['count'] >= 2 ) { $count++; }
        }
        return $count;
    }

    // ── Presence helpers ──────────────────────────────────────────────────

    /** Position of the normalized keyword inside a Rank Math focus CSV. */
    private function rankmath_role( string $normalized_keyword, string $csv ): string {
        if ( '' === $normalized_keyword || '' === trim( $csv ) ) {
            return 'absent';
        }
        $parts = array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ), 'strlen' ) );
        foreach ( $parts as $index => $part ) {
            if ( $this->normalizer->normalize_keyword( $part ) === $normalized_keyword ) {
                return 0 === $index ? 'primary' : 'extra';
            }
        }
        return 'absent';
    }

    /**
     * Plain normalized-token containment. Deliberately page-type-agnostic:
     * the Rank-Math-specific substring semantics live in
     * IndexReadinessGate::category_active_chip_coverage() and stay there;
     * this diagnostic reports raw presence only.
     */
    private function content_contains( string $normalized_keyword, string $normalized_content ): bool {
        if ( '' === $normalized_keyword || '' === $normalized_content ) {
            return false;
        }
        return false !== strpos( ' ' . $normalized_content . ' ', ' ' . $normalized_keyword . ' ' )
            || false !== strpos( $normalized_content, $normalized_keyword );
    }

    private function cluster_key( string $keyword, string $canonical ): string {
        $base = '' !== trim( $canonical ) ? $canonical : $keyword;
        $base = strtolower( $base );
        $base = (string) preg_replace( '/[^a-z0-9]+/', '', $base );
        return $base;
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $registry
     * @return array<string,mixed>|string
     */
    private function registry_value( array $registry, string $normalized, string $table ) {
        if ( in_array( $table, $this->missing_optional_tables, true ) ) {
            return 'table_missing';
        }
        return array_values( $registry[ $normalized ] ?? [] );
    }

    // ── Page loading (bounded cache, batch fetch) ─────────────────────────

    /**
     * @param array<int,int> $post_ids
     * @return array<int,array<string,mixed>>
     */
    private function load_pages( array $post_ids ): array {
        $missing = [];
        foreach ( $post_ids as $pid ) {
            if ( ! array_key_exists( $pid, $this->page_cache ) ) {
                $missing[] = (int) $pid;
            }
        }
        if ( [] !== $missing ) {
            if ( count( $this->page_cache ) > self::PAGE_CACHE_LIMIT ) {
                $this->page_cache = array_slice( $this->page_cache, - (int) ( self::PAGE_CACHE_LIMIT / 2 ), null, true );
            }
            foreach ( $this->fetch_pages( $missing ) as $pid => $page ) {
                $this->page_cache[ (int) $pid ] = $page;
            }
            foreach ( $missing as $pid ) {
                if ( ! array_key_exists( $pid, $this->page_cache ) ) {
                    $this->page_cache[ $pid ] = null; // proven unresolvable
                }
            }
        }
        $out = [];
        foreach ( $post_ids as $pid ) {
            if ( null !== ( $this->page_cache[ $pid ] ?? null ) ) {
                $out[ $pid ] = $this->page_cache[ $pid ];
            }
        }
        return $out;
    }

    // ── Indexing helpers ──────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int|string,array<int,array<string,mixed>>>
     */
    private function index_rows( array $rows, string $key ): array {
        $indexed = [];
        foreach ( $rows as $row ) {
            $indexed[ $row[ $key ] ?? 0 ][] = $row;
        }
        return $indexed;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function index_rows_by_keyword( array $rows ): array {
        $indexed = [];
        foreach ( $rows as $row ) {
            $normalized = (string) ( $row['normalized_keyword'] ?? '' );
            if ( '' === $normalized ) {
                $normalized = $this->normalizer->normalize_keyword( (string) ( $row['keyword'] ?? '' ) );
            }
            if ( '' !== $normalized ) {
                $indexed[ $normalized ][] = $row;
            }
        }
        return $indexed;
    }

    // ── Data fetchers (protected seams; SELECT/SHOW only) ─────────────────

    /** @return array<int,array<string,mixed>> */
    protected function fetch_candidates_chunk( int $after_id, int $limit ): array {
        global $wpdb;
        $columns = $this->get_columns( $this->candidates_table() );
        $wanted  = [ 'id', 'keyword', 'canonical', 'status', 'intent_type', 'entity_type', 'entity_id', 'target_type', 'target_id', 'target_name', 'target_slug' ];
        $select  = implode( ', ', array_values( array_intersect( $wanted, array_keys( $columns ) ) ) );
        if ( '' === $select ) { $select = 'id, keyword'; }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$select} FROM {$this->candidates_table()} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $after_id,
            $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** Three-column pre-pass for global near-duplicate clustering. */
    protected function build_cluster_map(): void {
        global $wpdb;
        $this->cluster_map = [];
        $columns  = $this->get_columns( $this->candidates_table() );
        $select   = isset( $columns['canonical'] ) ? 'id, keyword, canonical' : "id, keyword, '' AS canonical";
        $after_id = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT {$select} FROM {$this->candidates_table()} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $after_id,
                self::CHUNK_SIZE * 4
            ), ARRAY_A );
            $rows = is_array( $rows ) ? $rows : [];
            foreach ( $rows as $row ) {
                $after_id = (int) $row['id'];
                $key      = $this->cluster_key( (string) ( $row['keyword'] ?? '' ), (string) ( $row['canonical'] ?? '' ) );
                if ( '' === $key ) { continue; }
                if ( ! isset( $this->cluster_map[ $key ] ) ) {
                    $this->cluster_map[ $key ] = [ 'count' => 0, 'hash' => substr( sha1( $key ), 0, 10 ) ];
                }
                $this->cluster_map[ $key ]['count']++;
            }
        } while ( count( $rows ) === self::CHUNK_SIZE * 4 );
    }

    /** @param array<int,int> $candidate_ids @return array<int,array<string,mixed>> */
    protected function fetch_import_rows_by_candidate_ids( array $candidate_ids ): array {
        if ( [] === $candidate_ids || in_array( $this->rows_table(), $this->missing_optional_tables, true ) ) {
            return [];
        }
        global $wpdb;
        $in   = implode( ',', array_map( 'intval', $candidate_ids ) );
        $rows = $wpdb->get_results(
            "SELECT id, batch_id, import_batch_id, keyword, normalized_keyword, status, result_action, result_reason, target_type, target_id, target_name, candidate_id FROM {$this->rows_table()} WHERE candidate_id IN ({$in})",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /** @param array<int,string> $keywords @return array<int,array<string,mixed>> */
    protected function fetch_import_rows_by_keywords( array $keywords ): array {
        if ( [] === $keywords || in_array( $this->rows_table(), $this->missing_optional_tables, true ) ) {
            return [];
        }
        global $wpdb;
        $columns = $this->get_columns( $this->rows_table() );
        $match   = isset( $columns['normalized_keyword'] ) ? 'normalized_keyword' : 'keyword';
        $placeholders = implode( ',', array_fill( 0, count( $keywords ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, batch_id, import_batch_id, keyword, normalized_keyword, status, result_action, result_reason, target_type, target_id, target_name, candidate_id FROM {$this->rows_table()} WHERE {$match} IN ({$placeholders})",
            ...$keywords
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @param array<int,int> $batch_ids @return array<int,array<string,mixed>> */
    protected function fetch_batches( array $batch_ids ): array {
        if ( [] === $batch_ids || in_array( $this->batches_table(), $this->missing_optional_tables, true ) ) {
            return [];
        }
        global $wpdb;
        $in   = implode( ',', array_map( 'intval', $batch_ids ) );
        $rows = $wpdb->get_results(
            "SELECT id, import_batch_id, pool, target_type, target_id, target_name FROM {$this->batches_table()} WHERE id IN ({$in})",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Post-meta ownership registry (_tmwseo_keyword primary exact matches in
     * SQL; _tmwseo_secondary_keywords fetched once per chunk and matched in
     * PHP because it stores CSV/JSON).
     *
     * @param array<int,string> $keywords
     * @return array<string,array<int,array<string,mixed>>>
     */
    protected function fetch_postmeta_ownership( array $keywords ): array {
        if ( [] === $keywords ) { return []; }
        global $wpdb;
        $indexed      = [];
        $placeholders = implode( ',', array_fill( 0, count( $keywords ), '%s' ) );
        $primaries    = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tmwseo_keyword' AND meta_value IN ({$placeholders})",
            ...$keywords
        ), ARRAY_A );
        foreach ( is_array( $primaries ) ? $primaries : [] as $row ) {
            $normalized = $this->normalizer->normalize_keyword( (string) $row['meta_value'] );
            $indexed[ $normalized ][] = [ 'post_id' => (int) $row['post_id'], 'role' => 'primary' ];
        }
        $secondaries = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tmwseo_secondary_keywords' AND meta_value != ''",
            ARRAY_A
        );
        $keyword_set = array_flip( $keywords );
        foreach ( is_array( $secondaries ) ? $secondaries : [] as $row ) {
            $decoded = json_decode( (string) $row['meta_value'], true );
            $terms   = is_array( $decoded ) ? $decoded : explode( ',', (string) $row['meta_value'] );
            foreach ( $terms as $term ) {
                if ( ! is_scalar( $term ) ) { continue; }
                $normalized = $this->normalizer->normalize_keyword( (string) $term );
                if ( isset( $keyword_set[ $normalized ] ) ) {
                    $indexed[ $normalized ][] = [ 'post_id' => (int) $row['post_id'], 'role' => 'secondary' ];
                }
            }
        }
        return $indexed;
    }

    /** @param array<int,string> $keywords @return array<string,array<int,array<string,mixed>>> */
    protected function fetch_usage_rows( array $keywords ): array {
        if ( [] === $keywords || in_array( $this->usage_table(), $this->missing_optional_tables, true ) ) {
            return [];
        }
        global $wpdb;
        $columns = $this->get_columns( $this->usage_table() );
        if ( ! isset( $columns['keyword_text'] ) ) { return []; }
        $placeholders = implode( ',', array_fill( 0, count( $keywords ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->usage_table()} WHERE keyword_text IN ({$placeholders})",
            ...$keywords
        ), ARRAY_A );
        $indexed = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $normalized = $this->normalizer->normalize_keyword( (string) ( $row['keyword_text'] ?? '' ) );
            $indexed[ $normalized ][] = $row;
        }
        return $indexed;
    }

    /** @param array<int,string> $keywords @return array<string,array<int,array<string,mixed>>> */
    protected function fetch_cannibalization_rows( array $keywords ): array {
        if ( [] === $keywords || in_array( $this->cannibalization_table(), $this->missing_optional_tables, true ) ) {
            return [];
        }
        global $wpdb;
        $columns = $this->get_columns( $this->cannibalization_table() );
        if ( ! isset( $columns['keyword_text'] ) ) { return []; }
        $placeholders = implode( ',', array_fill( 0, count( $keywords ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->cannibalization_table()} WHERE keyword_text IN ({$placeholders})",
            ...$keywords
        ), ARRAY_A );
        $indexed = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $normalized = $this->normalizer->normalize_keyword( (string) ( $row['keyword_text'] ?? '' ) );
            $indexed[ $normalized ][] = $row;
        }
        return $indexed;
    }

    /**
     * Batch-load pages: ID, post_type, normalized content, Rank Math CSV.
     *
     * @param array<int,int> $post_ids
     * @return array<int,array<string,mixed>>
     */
    protected function fetch_pages( array $post_ids ): array {
        if ( [] === $post_ids ) { return []; }
        global $wpdb;
        $in    = implode( ',', array_map( 'intval', $post_ids ) );
        $posts = $wpdb->get_results(
            "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE ID IN ({$in})",
            ARRAY_A
        );
        $metas = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_focus_keyword' AND post_id IN ({$in})",
            ARRAY_A
        );
        $csv_by_post = [];
        foreach ( is_array( $metas ) ? $metas : [] as $meta ) {
            $csv_by_post[ (int) $meta['post_id'] ] = (string) $meta['meta_value'];
        }
        $pages = [];
        foreach ( is_array( $posts ) ? $posts : [] as $post ) {
            $pid = (int) $post['ID'];
            $pages[ $pid ] = [
                'post_type'          => (string) $post['post_type'],
                'rankmath_csv'       => $csv_by_post[ $pid ] ?? '',
                'content_normalized' => $this->normalize_content( (string) $post['post_content'] ),
            ];
        }
        return $pages;
    }

    protected function normalize_content( string $html ): string {
        $text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
        $text = strtolower( $text );
        $text = (string) preg_replace( '/[^\p{L}\p{N}\s\'"-]+/u', ' ', $text );
        $text = (string) preg_replace( '/\s+/u', ' ', $text );
        return trim( $text );
    }

    // ── Table helpers ─────────────────────────────────────────────────────

    protected function candidates_table(): string {
        global $wpdb; return $wpdb->prefix . 'tmw_keyword_candidates';
    }
    protected function batches_table(): string {
        global $wpdb; return $wpdb->prefix . 'tmw_keyword_import_batches';
    }
    protected function rows_table(): string {
        global $wpdb; return $wpdb->prefix . 'tmw_keyword_import_rows';
    }
    protected function cannibalization_table(): string {
        global $wpdb; return $wpdb->prefix . 'tmw_cannibalization_flags';
    }
    protected function usage_table(): string {
        global $wpdb; return $wpdb->prefix . 'tmwseo_keyword_usage';
    }
    protected function table_exists( string $table ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        return is_string( $found ) && strtolower( $found ) === strtolower( $table );
    }

    /** @return array<string,bool> */
    protected function get_columns( string $table ): array {
        if ( isset( $this->columns_cache[ $table ] ) ) {
            return $this->columns_cache[ $table ];
        }
        global $wpdb;
        $columns = [];
        $rows    = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $field = is_array( $row ) ? (string) ( $row['Field'] ?? $row['field'] ?? '' ) : '';
                if ( '' !== $field ) { $columns[ $field ] = true; }
            }
        }
        $this->columns_cache[ $table ] = $columns;
        return $columns;
    }

    /**
     * error_log only, deliberately: the plugin's Logs facility persists log
     * lines into a database table, which would violate this diagnostic's
     * strict read-only contract.
     */
    protected function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
