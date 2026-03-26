<?php
/**
 * CategoryBackfillRunner — Append-only category assignment for formula backfills.
 *
 * Rules:
 *  - ONLY appends the target category to posts that are missing it.
 *  - NEVER removes existing terms from any taxonomy.
 *  - NEVER modifies tags or other taxonomies.
 *  - Writes one audit log row per post processed.
 *  - Synchronous, v1. No cron. No batch queue.
 *
 * @package TMWSEO\Engine\CategoryFormulas
 * @since   5.2.0
 */
namespace TMWSEO\Engine\CategoryFormulas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryBackfillRunner {

    /** Default chunk size to avoid PHP timeouts on large post sets. */
    const CHUNK_SIZE = 100;

    /** @var CategoryFormulaEngine */
    private CategoryFormulaEngine $engine;

    /** @var CategoryFormulaRepository */
    private CategoryFormulaRepository $formula_repo;

    /**
     * @param CategoryFormulaEngine     $engine
     * @param CategoryFormulaRepository $formula_repo
     */
    public function __construct(
        CategoryFormulaEngine $engine,
        CategoryFormulaRepository $formula_repo
    ) {
        $this->engine       = $engine;
        $this->formula_repo = $formula_repo;
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Run one chunk of the backfill for a formula.
     *
     * Accepts $offset and $limit to support a chunked redirect loop from the
     * admin page when post counts are large. The caller (admin handler) is
     * responsible for accumulating the running changed total across chunks and
     * calling formula_repo->update_backfill_stats() with the full cumulative
     * count once has_more is false.
     *
     * @param object   $formula     Row from tmw_seo_category_formulas.
     * @param int|null $offset      Start offset into the matched-post list.
     * @param int|null $limit       Number of posts to process per run.
     * @return array {
     *   changed:     int,   — posts that had the category appended in this chunk
     *   skipped:     int,   — posts that already had it
     *   errors:      int,
     *   has_more:    bool,  — true when a next chunk exists
     *   next_offset: int,
     * }
     */
    public function run( object $formula, ?int $offset = null, ?int $limit = null ): array {
        $target_term_id  = (int) $formula->target_term_id;
        $target_taxonomy = $formula->target_taxonomy ?? 'category';

        if ( $target_term_id <= 0 ) {
            return $this->empty_result( 'invalid_target_term' );
        }

        // Verify the target term still exists.
        $target_term = get_term( $target_term_id, $target_taxonomy );
        if ( ! $target_term || is_wp_error( $target_term ) ) {
            return $this->empty_result( 'target_term_not_found' );
        }

        $all_ids = $this->engine->find_matching_post_ids( $formula );

        // Apply offset/limit for chunked runs.
        $offset   = max( 0, (int) ( $offset ?? 0 ) );
        $limit    = max( 1, (int) ( $limit ?? self::CHUNK_SIZE ) );
        $chunk    = array_slice( $all_ids, $offset, $limit );
        $has_more = count( $all_ids ) > ( $offset + $limit );

        $changed = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ( $chunk as $post_id ) {
            $result = $this->process_one( (int) $post_id, $formula, $target_term_id, $target_taxonomy );
            if ( $result === 'changed' ) {
                $changed++;
            } elseif ( $result === 'skipped' ) {
                $skipped++;
            } else {
                $errors++;
            }
        }

        return [
            'changed'     => $changed,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'has_more'    => $has_more,
            'next_offset' => $offset + $limit,
        ];
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Process a single post: append target term if missing, then write log.
     *
     * @param int    $post_id
     * @param object $formula
     * @param int    $target_term_id
     * @param string $target_taxonomy
     * @return string 'changed' | 'skipped' | 'error'
     */
    private function process_one(
        int $post_id,
        object $formula,
        int $target_term_id,
        string $target_taxonomy
    ): string {
        $before_ids = $this->engine->get_object_term_ids( $post_id, $target_taxonomy );

        if ( in_array( $target_term_id, $before_ids, true ) ) {
            $this->formula_repo->write_log( [
                'formula_id'      => (int) $formula->id,
                'post_id'         => $post_id,
                'action'          => 'backfill_check',
                'result'          => 'skipped',
                'before_term_ids' => $before_ids,
                'after_term_ids'  => $before_ids,
                'message'         => 'Already assigned.',
            ] );
            return 'skipped';
        }

        // Append the target term while preserving all existing terms.
        $new_ids = array_unique( array_merge( $before_ids, [ $target_term_id ] ) );
        $wp_result = wp_set_object_terms( $post_id, $new_ids, $target_taxonomy, false );

        if ( is_wp_error( $wp_result ) ) {
            $this->formula_repo->write_log( [
                'formula_id'      => (int) $formula->id,
                'post_id'         => $post_id,
                'action'          => 'backfill_assign',
                'result'          => 'error',
                'before_term_ids' => $before_ids,
                'after_term_ids'  => $before_ids,
                'message'         => $wp_result->get_error_message(),
            ] );
            return 'error';
        }

        // Verify the assignment was accepted.
        $after_ids = $this->engine->get_object_term_ids( $post_id, $target_taxonomy );

        $this->formula_repo->write_log( [
            'formula_id'      => (int) $formula->id,
            'post_id'         => $post_id,
            'action'          => 'backfill_assign',
            'result'          => 'ok',
            'before_term_ids' => $before_ids,
            'after_term_ids'  => $after_ids,
            'message'         => sprintf( 'Appended term %d to %s.', $target_term_id, $target_taxonomy ),
        ] );

        return 'changed';
    }

    /**
     * @param string $reason
     * @return array
     */
    private function empty_result( string $reason ): array {
        return [
            'changed'     => 0,
            'skipped'     => 0,
            'errors'      => 0,
            'has_more'    => false,
            'next_offset' => 0,
            'error'       => $reason,
        ];
    }
}
