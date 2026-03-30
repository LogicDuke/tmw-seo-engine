<?php
/**
 * TMW SEO Engine — Builder Candidate Service
 *
 * Activates the static niche pattern families into the preview/candidate
 * review queue. This is the ONLY class that should bridge the builder layer
 * to the preview layer.
 *
 * ============================================================
 * ARCHITECTURE BOUNDARY
 * ============================================================
 *
 * SOURCE:  data/niche-pattern-families.php  (builder layer)
 *          CuratedKeywordLibrary::generate_builder_candidates()
 *
 * DESTINATION:  tmw_seed_expansion_candidates  (preview/review layer)
 *               via ExpansionCandidateRepository::insert_batch()
 *
 * OUTPUT NEVER GOES TO:
 *   - tmwseo_seeds            ← trusted root store — NEVER
 *   - auto-approval           ← NEVER
 *   - SeedRegistry::register_trusted_seed()  ← NEVER
 *
 * Trigger:  manual admin action only (no cron, no background auto-run).
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.2.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BuilderCandidateService {

    // ── Source / rule labels (used in candidate provenance) ──────────────────

    /** Source identifier stored against each preview candidate row. */
    const SOURCE = 'builder_pattern_family';

    /** Generation rule stored against each preview candidate row. */
    const GENERATION_RULE = 'static_niche_pattern_builder';

    // ── Run limits ────────────────────────────────────────────────────────────

    /**
     * Default candidates generated per category per run.
     * Callers may override via $args['per_category_limit'].
     */
    const DEFAULT_PER_CATEGORY_LIMIT = 50;

    /**
     * Default max categories processed in one multi-category run.
     * Callers may override via $args['max_categories'].
     */
    const DEFAULT_MAX_CATEGORIES = 10;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate and queue preview candidates for a single category.
     *
     * 1. Calls CuratedKeywordLibrary::generate_builder_candidates()
     *    to get structured niche phrases from niche-pattern-families.php.
     * 2. Routes ALL phrases to ExpansionCandidateRepository::insert_batch()
     *    (preview/review layer only — never to trusted seeds).
     * 3. Returns a compact summary.
     *
     * @param string $category  Category slug, e.g. 'blonde', 'asian', 'milf'.
     * @param int    $limit     Candidate cap; 0 = use DEFAULT_PER_CATEGORY_LIMIT.
     * @return array{
     *   category: string,
     *   phrases_generated: int,
     *   inserted: int,
     *   skipped: int,
     *   batch_id: string,
     *   queue_was_full: bool,
     *   error: string
     * }
     */
    public static function insert_builder_candidates_for_category(
        string $category,
        int    $limit = 0
    ): array {
        $category = sanitize_title( $category );
        $limit    = $limit > 0 ? $limit : self::DEFAULT_PER_CATEGORY_LIMIT;

        $result = [
            'category'          => $category,
            'phrases_generated' => 0,
            'inserted'          => 0,
            'skipped'           => 0,
            'batch_id'          => '',
            'queue_was_full'    => false,
            'error'             => '',
        ];

        if ( $category === '' ) {
            $result['error'] = 'empty_category';
            return $result;
        }

        // ── Check queue before doing any work ─────────────────────────────
        if ( ExpansionCandidateRepository::is_queue_full() ) {
            $result['queue_was_full'] = true;
            $result['error']          = 'queue_full';
            Logs::info( 'keywords', '[TMW-BUILDER] insert_builder_candidates_for_category skipped — queue full', [
                'category' => $category,
            ] );
            return $result;
        }

        // ── Generate candidate phrases ────────────────────────────────────
        $phrases = CuratedKeywordLibrary::generate_builder_candidates( $category, $limit );

        if ( empty( $phrases ) ) {
            // Category exists but has no niche pattern family — not an error,
            // just nothing to queue. Log at debug level only.
            Logs::debug( 'keywords', '[TMW-BUILDER] No niche pattern family for category', [
                'category' => $category,
            ] );
            $result['error'] = 'no_pattern_family';
            return $result;
        }

        $result['phrases_generated'] = count( $phrases );

        // ── Insert batch into preview layer ───────────────────────────────
        // NEVER route to SeedRegistry::register_trusted_seed().
        $batch = ExpansionCandidateRepository::insert_batch(
            $phrases,
            self::SOURCE,
            self::GENERATION_RULE,
            'system',
            0,
            [
                'generator'      => 'BuilderCandidateService',
                'category'       => $category,
                'pattern_source' => 'niche-pattern-families.php',
            ]
        );

        $result['inserted']      = (int) ( $batch['inserted'] ?? 0 );
        $result['skipped']       = (int) ( $batch['skipped']  ?? 0 );
        $result['batch_id']      = (string) ( $batch['batch_id'] ?? '' );

        Logs::info( 'keywords', '[TMW-BUILDER] Builder candidates queued for preview', [
            'category'          => $category,
            'phrases_generated' => $result['phrases_generated'],
            'inserted'          => $result['inserted'],
            'skipped'           => $result['skipped'],
            'batch_id'          => $result['batch_id'],
        ] );

        return $result;
    }

    /**
     * Generate and queue preview candidates for a set of categories.
     *
     * Bounded by $args['max_categories'] (default 10) and
     * $args['per_category_limit'] (default 50) to prevent queue flooding.
     *
     * @param string[] $categories  Category slugs. Empty = all niche_pattern_categories().
     * @param array    $args {
     *   @type int $per_category_limit  Candidates per category (default 50).
     *   @type int $max_categories      Max categories to process (default 10).
     * }
     * @return array{
     *   categories_processed: int,
     *   categories_skipped_queue_full: int,
     *   phrases_generated: int,
     *   inserted: int,
     *   skipped_duplicates: int,
     *   filtered_no_family: int,
     *   batch_ids: string[],
     *   queue_full_abort: bool
     * }
     */
    public static function run_builder_candidate_bootstrap(
        array $categories = [],
        array $args       = []
    ): array {
        $per_cat_limit  = max( 1, (int) ( $args['per_category_limit'] ?? self::DEFAULT_PER_CATEGORY_LIMIT ) );
        $max_categories = max( 1, (int) ( $args['max_categories']     ?? self::DEFAULT_MAX_CATEGORIES ) );

        // Resolve category list
        if ( empty( $categories ) ) {
            $categories = CuratedKeywordLibrary::niche_pattern_categories();
        }

        // Sanitize and deduplicate
        $categories = array_values( array_unique(
            array_filter( array_map( 'sanitize_title', $categories ) )
        ) );

        // Cap category count
        if ( count( $categories ) > $max_categories ) {
            $categories = array_slice( $categories, 0, $max_categories );
        }

        $summary = [
            'categories_processed'            => 0,
            'categories_skipped_queue_full'   => 0,
            'phrases_generated'               => 0,
            'inserted'                        => 0,
            'skipped_duplicates'              => 0,
            'filtered_no_family'              => 0,
            'batch_ids'                       => [],
            'queue_full_abort'                => false,
        ];

        foreach ( $categories as $category ) {
            // Re-check queue before each category — queue may fill mid-run
            if ( ExpansionCandidateRepository::is_queue_full() ) {
                $summary['queue_full_abort'] = true;
                $summary['categories_skipped_queue_full']++;
                Logs::info( 'keywords', '[TMW-BUILDER] Bootstrap halted — queue full mid-run', [
                    'stopped_at_category' => $category,
                ] );
                break;
            }

            $cat_result = self::insert_builder_candidates_for_category( $category, $per_cat_limit );

            if ( $cat_result['error'] === 'no_pattern_family' ) {
                $summary['filtered_no_family']++;
                continue;
            }

            if ( $cat_result['queue_was_full'] ) {
                $summary['queue_full_abort'] = true;
                $summary['categories_skipped_queue_full']++;
                break;
            }

            $summary['categories_processed']++;
            $summary['phrases_generated']  += $cat_result['phrases_generated'];
            $summary['inserted']           += $cat_result['inserted'];
            $summary['skipped_duplicates'] += $cat_result['skipped'];

            if ( $cat_result['batch_id'] !== '' ) {
                $summary['batch_ids'][] = $cat_result['batch_id'];
            }
        }

        Logs::info( 'keywords', '[TMW-BUILDER] Builder candidate bootstrap complete', $summary );

        return $summary;
    }
}
