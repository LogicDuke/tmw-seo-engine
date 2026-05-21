<?php
/**
 * ModelOpportunityImportService
 *
 * Parses KWS Everywhere CSV exports and writes rows into the model-opportunity
 * and model-opportunity-keyword tables.
 *
 * Changes in v5.10.0
 * ──────────────────
 * 1. kws_seo_score and kws_competition are now extracted from each parsed row
 *    and passed to ModelOpportunityScorer::score() so the zero-competition bonus
 *    and SEO-score contribution are applied correctly.
 *
 * 2. Total Volume rows now only UPDATE an existing opportunity's family_volume.
 *    They no longer CREATE a new opportunity record when none exists.
 *    A non-fatal import note is logged instead.
 *
 * 3. opportunity_type() now handles the 'manual_review' role explicitly:
 *    model-name + adult modifier keywords are kept as reviewable opportunities,
 *    not discarded as noise_archive.
 *
 * 4. kws_seo_score and kws_competition are stored in tmwseo_model_opportunity_keywords
 *    for per-keyword provenance when the schema columns exist.
 *
 * @package TMWSEO\Engine\Opportunities
 * @since   5.10.0
 */

namespace TMWSEO\Engine\Opportunities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelOpportunityImportService {

    // ── KWS column aliases → internal names ──────────────────────────────

    private const KWS_COLUMNS = [
        'keyword'        => 'keyword',
        'volume'         => 'volume',
        'trend'          => 'trend',
        'trend dir.'     => 'trend_dir',
        'seo score'      => 'seo_score',
        'traffic value'  => 'traffic_value',
        'competition'    => 'competition',
        'ad difficulty'  => 'ad_difficulty',
        'lowest cpc'     => 'lowest_cpc',
        'average cpc'    => 'average_cpc',
        'highest cpc'    => 'highest_cpc',
        'cpc spread'     => 'cpc_spread',
    ];

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $context
     * @return array{row_count:int,created_count:int,updated_count:int,noise_count:int,failed_count:int,preview:array}
     */
    public static function import(
        int    $import_id,
        string $mode,
        string $csv_path,
        array  $context  = [],
        bool   $preview  = false
    ): array {
        $rows = self::parse_csv( $csv_path );
        return self::apply_rows( $import_id, $mode, $rows, $context, $preview );
    }

    /**
     * Parse a KWS Everywhere CSV into normalised row arrays.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function parse_csv( string $csv_path ): array {
        $fh = fopen( $csv_path, 'rb' );
        if ( ! $fh ) { return []; }

        $header = null;
        $out    = [];

        while ( ( $line = fgetcsv( $fh ) ) !== false ) {
            $line = array_map( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v, $line );

            if ( $header === null ) {
                $norm   = array_map(
                    static fn( $h ) => ModelOpportunityNormalizer::normalize_keyword( (string) $h ),
                    $line
                );
                $header = [];
                foreach ( $norm as $i => $h ) {
                    if ( isset( self::KWS_COLUMNS[ $h ] ) ) {
                        $header[ $i ] = self::KWS_COLUMNS[ $h ];
                    }
                }
                continue;
            }

            if ( empty( array_filter( $line, static fn( $v ) => $v !== null && $v !== '' ) ) ) {
                continue;
            }

            $row = [ 'raw' => $line ];
            foreach ( $header as $i => $k ) {
                $row[ $k ] = $line[ $i ] ?? '';
            }
            $out[] = $row;
        }

        fclose( $fh );
        return $out;
    }

    /**
     * Apply parsed rows to the database (or build a preview array).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $context
     * @return array{row_count:int,created_count:int,updated_count:int,noise_count:int,failed_count:int,preview:array}
     */
    public static function apply_rows(
        int    $import_id,
        string $mode,
        array  $rows,
        array  $context = [],
        bool   $preview = false
    ): array {
        global $wpdb;

        $result = [
            'row_count'     => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'noise_count'   => 0,
            'failed_count'  => 0,
            'preview'       => [],
        ];

        $model_map = self::build_model_map();
        $opp_table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $kw_table  = $wpdb->prefix . 'tmwseo_model_opportunity_keywords';

        // Detect optional schema columns added in v5.10.0.
        $has_score_explanation = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$opp_table} LIKE %s", 'score_explanation' )
        );
        $has_kws_seo_score = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$opp_table} LIKE %s", 'kws_seo_score' )
        );
        $has_kws_competition = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$opp_table} LIKE %s", 'kws_competition' )
        );
        $has_page_type = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$opp_table} LIKE %s", 'page_type' )
        );
        $kw_has_seo_score   = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$kw_table} LIKE %s", 'seo_score' )
        );
        $kw_has_competition = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM {$kw_table} LIKE %s", 'competition' )
        );

        if ( $mode === 'kws_single_model_family' ) {
            return self::apply_single_model_family_rows(
                $import_id,
                $rows,
                $context,
                $preview,
                $result,
                $model_map,
                $opp_table,
                $kw_table,
                [
                    'has_score_explanation' => $has_score_explanation,
                    'has_kws_seo_score'     => $has_kws_seo_score,
                    'has_kws_competition'   => $has_kws_competition,
                    'has_page_type'         => $has_page_type,
                    'kw_has_seo_score'      => $kw_has_seo_score,
                    'kw_has_competition'    => $kw_has_competition,
                ]
            );
        }

        foreach ( $rows as $row ) {
            $result['row_count']++;

            $keyword = trim( (string) ( $row['keyword'] ?? '' ) );
            if ( $keyword === '' ) { continue; }

            $is_total = str_starts_with( strtolower( $keyword ), 'total volume' );

            $entity = self::resolve_entity( $keyword, $mode, $context, $model_map, $is_total );
            if ( $entity === '' ) {
                if ( ! $is_total ) { $result['noise_count']++; }
                continue;
            }

            $canonical = ModelOpportunityNormalizer::canonical_entity_key( $entity );
            $vol       = (int) preg_replace( '/[^0-9]/', '', (string) ( $row['volume'] ?? '0' ) );

            // ── Total Volume row ──────────────────────────────────────────
            // FIX v5.10.0: Total Volume rows must ONLY update family_volume on an
            // EXISTING opportunity. They must NEVER create a new opportunity record.
            if ( $is_total ) {
                if ( $vol <= 0 ) { continue; }

                if ( $preview ) {
                    $result['preview'][] = [
                        'entity'           => $entity,
                        'opportunity_type' => 'total_volume_summary',
                        'family_volume'    => $vol,
                        'total_volume_row' => true,
                    ];
                    continue;
                }

                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$opp_table} WHERE canonical_entity_key=%s LIMIT 1",
                        $canonical
                    ),
                    ARRAY_A
                );

                if ( $existing ) {
                    $ok = $wpdb->update(
                        $opp_table,
                        [ 'family_volume' => $vol, 'updated_at' => current_time( 'mysql' ) ],
                        [ 'id' => (int) $existing['id'] ]
                    );
                    if ( $ok === false ) {
                        $result['failed_count']++;
                        error_log( '[TMW-MODEL-OPP] update_failed total_volume canonical=' . $canonical . ' error=' . $wpdb->last_error );
                    } else {
                        $result['updated_count']++;
                    }
                } else {
                    // No existing record — log a note and skip. Do NOT create a record.
                    error_log( '[TMW-MODEL-OPP] total_volume_skipped_no_existing_record canonical=' . $canonical . ' vol=' . $vol );
                }
                continue;
            }

            // ── Regular keyword row ───────────────────────────────────────

            $role             = ModelKeywordRoleClassifier::classify( $keyword, $entity );
            $opportunity_type = self::opportunity_type( $mode, $role, $entity, $context );
            $traffic          = (float) preg_replace( '/[^0-9.]/', '', (string) ( $row['traffic_value'] ?? '0' ) );

            // Extract KWS signal columns for scorer.
            $kws_seo_score   = self::parse_kws_decimal( $row['seo_score']   ?? '' );
            $kws_competition = self::parse_kws_competition( $row['competition'] ?? '' );

            if ( $preview ) {
                $result['preview'][] = compact(
                    'keyword', 'entity', 'role', 'opportunity_type', 'vol', 'traffic'
                ) + [
                    'kws_seo_score'   => $kws_seo_score,
                    'kws_competition' => $kws_competition,
                ];
                continue;
            }

            if ( $role === 'noise' || $opportunity_type === 'noise_archive' ) {
                $result['noise_count']++;
            }

            // ── Build scored payload ──────────────────────────────────────
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$opp_table} WHERE canonical_entity_key=%s LIMIT 1",
                    $canonical
                ),
                ARRAY_A
            );

            $score_input = [
                'primary_volume'                           => $vol,
                'family_volume'                            => (int) ( $existing['family_volume'] ?? 0 ),
                'traffic_value'                            => $traffic,
                'matched_post_id'                          => (int) ( $existing['matched_post_id'] ?? 0 ),
                'platform_signals_count'                   => (int) ( $existing['platform_signals_count'] ?? 0 ),
                'competitor_signal'                        => (int) ( $existing['competitor_signal'] ?? 0 ),
                'manual_competitor_exact_match_weakness'   => (int) ( $existing['manual_competitor_exact_match_weakness'] ?? 0 ),
                'kws_seo_score'                            => $kws_seo_score,   // NEW v5.10.0
                'kws_competition'                          => $kws_competition, // NEW v5.10.0
            ];

            $score_result = ModelOpportunityScorer::score( $score_input );

            $payload = [
                'canonical_entity_key' => $canonical,
                'model_entity'         => $entity,
                'opportunity_type'     => $opportunity_type,
                'primary_keyword'      => $keyword,
                'primary_volume'       => $vol,
                'traffic_value'        => $traffic,
                'score'                => $score_result['score'],
                'priority'             => $score_result['priority'],
                'updated_at'           => current_time( 'mysql' ),
            ];

            // Conditionally add v5.10.0 columns if they exist.
            if ( $has_score_explanation ) {
                $payload['score_explanation'] = $score_result['score_explanation'];
            }
            if ( $has_kws_seo_score && $kws_seo_score >= 0 ) {
                $payload['kws_seo_score'] = $kws_seo_score;
            }
            if ( $has_kws_competition && $kws_competition >= 0 ) {
                $payload['kws_competition'] = $kws_competition;
            }
            if ( $has_page_type ) {
                $payload['page_type'] = $context['page_type'] ?? 'model_page';
            }

            $opp_id = 0;

            if ( $existing ) {
                $ok = $wpdb->update( $opp_table, $payload, [ 'id' => (int) $existing['id'] ] );
                if ( $ok === false ) {
                    $result['failed_count']++;
                    error_log( '[TMW-MODEL-OPP] update_failed canonical=' . $canonical . ' error=' . $wpdb->last_error );
                    continue;
                }
                $opp_id = (int) $existing['id'];
                $result['updated_count']++;
            } else {
                $payload['created_at'] = current_time( 'mysql' );
                $ok = $wpdb->insert( $opp_table, $payload );
                if ( $ok === false || (int) $wpdb->insert_id <= 0 ) {
                    $result['failed_count']++;
                    error_log( '[TMW-MODEL-OPP] insert_failed canonical=' . $canonical . ' error=' . $wpdb->last_error );
                    continue;
                }
                $opp_id = (int) $wpdb->insert_id;
                $result['created_count']++;
            }

            // ── Write per-keyword row ─────────────────────────────────────
            $kw_payload = [
                'opportunity_id'      => $opp_id,
                'import_id'           => $import_id,
                'keyword'             => $keyword,
                'normalized_keyword'  => ModelOpportunityNormalizer::normalize_keyword( $keyword ),
                'role'                => $role,
                'volume'              => $vol,
                'source'              => ( $context['source'] ?? null ),
                'competitor_domain'   => ( $context['competitor_domain'] ?? null ),
                'platform_detected'   => ( $context['platform'] ?? null ),
                'raw_row_json'        => wp_json_encode( $row ),
                'created_at'          => current_time( 'mysql' ),
                'updated_at'          => current_time( 'mysql' ),
            ];

            if ( $kw_has_seo_score && $kws_seo_score >= 0 ) {
                $kw_payload['seo_score'] = $kws_seo_score;
            }
            if ( $kw_has_competition && $kws_competition >= 0 ) {
                $kw_payload['competition'] = $kws_competition;
            }

            $kw_ok = $wpdb->insert( $kw_table, $kw_payload );
            if ( $kw_ok === false ) {
                $result['failed_count']++;
                error_log( '[TMW-MODEL-OPP] keyword_insert_failed canonical=' . $canonical . ' error=' . $wpdb->last_error );
            }
        }

        return $result;
    }

    /**
     * Dedicated importer path for kws_single_model_family.
     * Aggregates once at family level, inserts all keyword rows.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $schema
     * @param array<string,string> $model_map
     * @return array{row_count:int,created_count:int,updated_count:int,noise_count:int,failed_count:int,preview:array}
     */
    private static function apply_single_model_family_rows(
        int   $import_id,
        array $rows,
        array $context,
        bool  $preview,
        array $result,
        array $model_map,
        string $opp_table,
        string $kw_table,
        array $schema
    ): array {
        global $wpdb;

        $model = trim( (string) ( $context['model_entity'] ?? '' ) );
        $entity = self::match_model( $model, $model_map ) ?: ModelOpportunityNormalizer::normalize_model_name( $model );
        $canonical = ModelOpportunityNormalizer::canonical_entity_key( $entity );
        $norm_model = ModelOpportunityNormalizer::normalize_keyword( $entity );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$opp_table} WHERE canonical_entity_key=%s LIMIT 1", $canonical ), ARRAY_A );
        $matched_post_id = (int) ( $existing['matched_post_id'] ?? 0 );
        $family_opportunity_type = $matched_post_id > 0 ? 'existing_model_optimization' : 'missing_model_acquisition';

        $candidates = [];
        $total_volume = 0;

        foreach ( $rows as $row ) {
            $result['row_count']++;
            $keyword = trim( (string) ( $row['keyword'] ?? '' ) );
            if ( $keyword === '' ) { continue; }
            $is_total = str_starts_with( strtolower( $keyword ), 'total volume' );
            $vol = (int) preg_replace( '/[^0-9]/', '', (string) ( $row['volume'] ?? '0' ) );
            if ( $is_total ) {
                if ( $vol > 0 ) { $total_volume = $vol; }
                if ( $preview ) {
                    $result['preview'][] = [
                        'entity' => $entity,
                        'opportunity_type' => 'total_volume_summary',
                        'family_volume' => $vol,
                        'total_volume_row' => true,
                    ];
                }
                continue;
            }

            $traffic = (float) preg_replace( '/[^0-9.]/', '', (string) ( $row['traffic_value'] ?? '0' ) );
            $kws_seo_score = self::parse_kws_decimal( $row['seo_score'] ?? '' );
            $kws_competition = self::parse_kws_competition( $row['competition'] ?? '' );
            $role = ModelKeywordRoleClassifier::classify( $keyword, $entity );

            $candidates[] = compact( 'row', 'keyword', 'vol', 'traffic', 'kws_seo_score', 'kws_competition', 'role' );
            if ( ! in_array( $role, [ 'noise', 'risky_explicit' ], true ) ) {
                $result['noise_count'] += 0;
            }

            if ( $preview ) {
                $result['preview'][] = [
                    'keyword' => $keyword,
                    'entity' => $entity,
                    'role' => $role,
                    'opportunity_type' => $family_opportunity_type,
                    'vol' => $vol,
                    'traffic' => $traffic,
                    'kws_seo_score' => $kws_seo_score,
                    'kws_competition' => $kws_competition,
                ];
            }
        }

        if ( empty( $candidates ) || $preview ) {
            return $result;
        }

        $pick = self::select_family_primary_candidate( $candidates, $norm_model );
        if ( $pick === null ) { return $result; }

        $family_volume = $total_volume > 0 ? $total_volume : array_sum( array_map( static fn( $c ) => (int) $c['vol'], array_filter( $candidates, static fn( $c ) => ! in_array( $c['role'], [ 'noise', 'risky_explicit' ], true ) ) ) );
        $max_safe_traffic = 0.0;
        foreach ( $candidates as $c ) {
            if ( in_array( $c['role'], [ 'manual_review', 'risky_explicit', 'noise' ], true ) ) { continue; }
            $max_safe_traffic = max( $max_safe_traffic, (float) $c['traffic'] );
        }
        $traffic = max( (float) $pick['traffic'], $max_safe_traffic );

        $score_result = ModelOpportunityScorer::score( [
            'primary_volume' => (int) $pick['vol'],
            'family_volume' => $family_volume,
            'traffic_value' => $traffic,
            'matched_post_id' => (int) ( $existing['matched_post_id'] ?? 0 ),
            'platform_signals_count' => (int) ( $existing['platform_signals_count'] ?? 0 ),
            'competitor_signal' => (int) ( $existing['competitor_signal'] ?? 0 ),
            'manual_competitor_exact_match_weakness' => (int) ( $existing['manual_competitor_exact_match_weakness'] ?? 0 ),
            'kws_seo_score' => (float) $pick['kws_seo_score'],
            'kws_competition' => (float) $pick['kws_competition'],
        ] );

        $opportunity_type = $family_opportunity_type;

        $payload = [
            'canonical_entity_key' => $canonical,
            'model_entity' => $entity,
            'opportunity_type' => $opportunity_type,
            'primary_keyword' => (string) $pick['keyword'],
            'primary_volume' => (int) $pick['vol'],
            'family_volume' => $family_volume,
            'traffic_value' => $traffic,
            'score' => $score_result['score'],
            'priority' => $score_result['priority'],
            'updated_at' => current_time( 'mysql' ),
        ];
        if ( $schema['has_score_explanation'] ) { $payload['score_explanation'] = $score_result['score_explanation']; }
        if ( $schema['has_kws_seo_score'] && (float) $pick['kws_seo_score'] >= 0 ) { $payload['kws_seo_score'] = (float) $pick['kws_seo_score']; }
        if ( $schema['has_kws_competition'] && (float) $pick['kws_competition'] >= 0 ) { $payload['kws_competition'] = (float) $pick['kws_competition']; }
        if ( $schema['has_page_type'] ) { $payload['page_type'] = $context['page_type'] ?? 'model_page'; }

        if ( $existing ) {
            $ok = $wpdb->update( $opp_table, $payload, [ 'id' => (int) $existing['id'] ] );
            if ( $ok === false ) { $result['failed_count']++; return $result; }
            $opp_id = (int) $existing['id'];
            $result['updated_count']++;
        } else {
            $payload['created_at'] = current_time( 'mysql' );
            $ok = $wpdb->insert( $opp_table, $payload );
            if ( $ok === false || (int) $wpdb->insert_id <= 0 ) { $result['failed_count']++; return $result; }
            $opp_id = (int) $wpdb->insert_id;
            $result['created_count']++;
        }

        foreach ( $candidates as $c ) {
            $kw_payload = [
                'opportunity_id' => $opp_id,
                'import_id' => $import_id,
                'keyword' => $c['keyword'],
                'normalized_keyword' => ModelOpportunityNormalizer::normalize_keyword( (string) $c['keyword'] ),
                'role' => $c['role'],
                'volume' => (int) $c['vol'],
                'source' => ( $context['source'] ?? null ),
                'competitor_domain' => ( $context['competitor_domain'] ?? null ),
                'platform_detected' => ( $context['platform'] ?? null ),
                'raw_row_json' => wp_json_encode( $c['row'] ),
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ];
            if ( $schema['kw_has_seo_score'] && (float) $c['kws_seo_score'] >= 0 ) { $kw_payload['seo_score'] = (float) $c['kws_seo_score']; }
            if ( $schema['kw_has_competition'] && (float) $c['kws_competition'] >= 0 ) { $kw_payload['competition'] = (float) $c['kws_competition']; }
            $wpdb->insert( $kw_table, $kw_payload );
        }

        return $result;
    }

    private static function select_family_primary_candidate( array $candidates, string $normalized_entity ): ?array {
        $buckets = [
            static fn( $c ) => $c['role'] === 'primary',
            static fn( $c ) => ModelOpportunityNormalizer::normalize_keyword( (string) $c['keyword'] ) === $normalized_entity,
            static fn( $c ) => $c['role'] === 'rankmath_candidate',
            static fn( $c ) => $c['role'] === 'content_support',
        ];
        foreach ( $buckets as $filter ) {
            $pool = array_values( array_filter( $candidates, static function( $c ) use ( $filter ) {
                if ( in_array( $c['role'], [ 'manual_review', 'risky_explicit', 'noise' ], true ) ) { return false; }
                return $filter( $c );
            } ) );
            if ( ! empty( $pool ) ) {
                usort( $pool, static fn( $a, $b ) => (int) $b['vol'] <=> (int) $a['vol'] );
                return $pool[0];
            }
        }
        return null;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Resolve the model entity name for a keyword row.
     */
    private static function resolve_entity(
        string $keyword,
        string $mode,
        array  $context,
        array  $model_map,
        bool   $is_total
    ): string {
        $model = trim( (string) ( $context['model_entity'] ?? '' ) );

        if ( $mode === 'kws_single_model_family' && $model !== '' ) {
            return self::match_model( $model, $model_map )
                ?: ModelOpportunityNormalizer::normalize_model_name( $model );
        }

        if ( $is_total && $model === '' ) {
            return '';
        }

        if ( $model === '' ) {
            $model = self::extract_model_from_keyword( $keyword );
        }

        return self::match_model( $model, $model_map )
            ?: ModelOpportunityNormalizer::normalize_model_name( $model );
    }

    /**
     * Build a canonical-key → post-title lookup from existing model posts.
     *
     * @return array<string,string>
     */
    private static function build_model_map(): array {
        $posts = get_posts( [
            'post_type'      => 'model_bio',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $map = [];
        foreach ( $posts as $id ) {
            $title = (string) get_the_title( $id );
            $slug  = (string) get_post_field( 'post_name', $id );
            foreach ( [
                $title,
                $slug,
                ModelOpportunityNormalizer::normalize_model_name( $title ),
                ModelOpportunityNormalizer::compact_name_key( $title ),
            ] as $k ) {
                $map[ ModelOpportunityNormalizer::compact_name_key( $k ) ] = $title;
            }
        }

        return $map;
    }

    private static function match_model( string $model, array $map ): string {
        $k = ModelOpportunityNormalizer::compact_name_key( $model );
        return $map[ $k ] ?? '';
    }

    private static function extract_model_from_keyword( string $keyword ): string {
        $parts = preg_split(
            '/\b(porn|sex|xxx|nude|onlyfans|fansly|livejasmin|camsoda)\b/i',
            $keyword
        );
        return trim( (string) ( $parts[0] ?? $keyword ) );
    }

    /**
     * Determine the opportunity type for a row.
     *
     * v5.10.0: 'manual_review' role is now handled explicitly.
     * Keywords with model name + adult modifier are market intelligence,
     * not noise — they become existing_model_optimization or
     * missing_model_acquisition depending on match state.
     */
    private static function opportunity_type(
        string $mode,
        string $role,
        string $entity,
        array  $context
    ): string {
        if ( $role === 'noise' ) { return 'noise_archive'; }
        if ( $mode === 'kws_competitor_keywords' ) { return 'competitor_gap'; }
        if ( $mode === 'platform_model_list' )     { return 'platform_coverage'; }

        if ( $entity === '' || ! empty( $context['missing_model'] ) ) {
            return 'missing_model_acquisition';
        }

        if ( in_array( $role, [ 'primary', 'content_support', 'manual_review' ], true ) ) {
            return 'existing_model_optimization';
        }

        if ( $role === 'platform_intent' ) { return 'platform_coverage'; }

        return 'generic_keyword_candidate';
    }

    /**
     * Parse a decimal value from a KWS string field.
     * Returns -1.0 if the value is empty or unparsable (= unknown).
     */
    private static function parse_kws_decimal( string $raw ): float {
        $raw = trim( $raw );
        if ( $raw === '' || $raw === 'N/A' || $raw === 'n/a' ) { return -1.0; }
        $clean = preg_replace( '/[^0-9.]/', '', $raw );
        if ( $clean === '' || $clean === '.' ) { return -1.0; }
        return (float) $clean;
    }

    /**
     * Parse the KWS Competition column.
     *
     * KWS exports competition as a decimal 0.0-1.0 (or occasionally 0-100 integer).
     * We normalise to 0.0-1.0.  Returns -1.0 if unknown.
     */
    private static function parse_kws_competition( string $raw ): float {
        $v = self::parse_kws_decimal( $raw );
        if ( $v < 0 ) { return -1.0; }
        // KWS sometimes exports competition as an integer 0-100 — normalise.
        if ( $v > 1.0 ) { $v = $v / 100.0; }
        return min( 1.0, max( 0.0, $v ) );
    }
}
