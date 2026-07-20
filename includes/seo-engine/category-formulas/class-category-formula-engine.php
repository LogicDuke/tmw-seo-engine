<?php
/**
 * CategoryFormulaEngine — Post matching logic for category formulas.
 *
 * Implements the formula matching model:
 *  - A post matches if, for every required_group, it has at least one term from that
 *    group in the source taxonomy (ANY within group, ALL across required groups).
 *  - A post is excluded if it has any term from an excluded_group in the source taxonomy.
 *
 * This class is pure matching logic and does NOT write to the database.
 *
 * @package TMWSEO\Engine\CategoryFormulas
 * @since   5.2.0
 */
namespace TMWSEO\Engine\CategoryFormulas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFormulaEngine {

    /** @var SignalGroupRepository */
    private SignalGroupRepository $group_repo;

    /** @var CategoryFormulaRepository */
    private CategoryFormulaRepository $formula_repo;

    /**
     * @param SignalGroupRepository     $group_repo
     * @param CategoryFormulaRepository $formula_repo
     */
    public function __construct(
        SignalGroupRepository $group_repo,
        CategoryFormulaRepository $formula_repo
    ) {
        $this->group_repo   = $group_repo;
        $this->formula_repo = $formula_repo;
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Run a dry run for a formula.
     * Returns match counts and a sample of matching posts (max 25).
     *
     * @param object $formula  Row from tmw_seo_category_formulas.
     * @return array {
     *   matched:       int,
     *   already_count: int,
     *   missing_count: int,
     *   sample:        array<int,array{post_id,title,current_cats,already_assigned}>
     * }
     */
    public function dry_run( object $formula ): array {
        $matched_ids      = $this->find_matching_post_ids( $formula );
        $target_term_id   = (int) $formula->target_term_id;
        $target_taxonomy  = $formula->target_taxonomy;

        $already_count = 0;
        $missing_count = 0;
        $sample        = [];

        foreach ( $matched_ids as $post_id ) {
            $current_term_ids = $this->get_object_term_ids( $post_id, $target_taxonomy );
            $already          = in_array( $target_term_id, $current_term_ids, true );

            if ( $already ) {
                $already_count++;
            } else {
                $missing_count++;
            }

            if ( count( $sample ) < 25 ) {
                $term_names = [];
                foreach ( $current_term_ids as $tid ) {
                    $term = get_term( $tid, $target_taxonomy );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $term_names[] = $term->name;
                    }
                }
                $sample[] = [
                    'post_id'          => $post_id,
                    'title'            => get_the_title( $post_id ),
                    'current_cats'     => implode( ', ', $term_names ),
                    'already_assigned' => $already,
                ];
            }
        }

        return [
            'matched'       => count( $matched_ids ),
            'already_count' => $already_count,
            'missing_count' => $missing_count,
            'sample'        => $sample,
        ];
    }

    /**
     * Return the full list of post IDs that match the formula.
     *
     * @param object $formula
     * @return int[]
     */
    public function find_matching_post_ids( object $formula ): array {
        $required_group_ids = $this->formula_repo->get_required_group_ids( (int) $formula->id );
        $excluded_group_ids = $this->formula_repo->get_excluded_group_ids( (int) $formula->id );

        if ( empty( $required_group_ids ) ) {
            return [];
        }

        $source_taxonomy = $formula->source_taxonomy ?? 'post_tag';
        $post_type       = $formula->post_type ?? 'post';

        // Build term-ID sets for each required group.
        $required_term_sets = [];
        foreach ( $required_group_ids as $gid ) {
            $ids = $this->group_repo->get_term_ids_for_group( (int) $gid, $source_taxonomy );
            if ( empty( $ids ) ) {
                // A required group with no terms can never match.
                return [];
            }
            $required_term_sets[] = $ids;
        }

        // Build excluded term IDs (union across all excluded groups).
        $excluded_term_ids = [];
        foreach ( $excluded_group_ids as $gid ) {
            $ids = $this->group_repo->get_term_ids_for_group( (int) $gid, $source_taxonomy );
            foreach ( $ids as $id ) {
                $excluded_term_ids[ $id ] = true;
            }
        }

        return $this->query_matching_posts(
            $required_term_sets,
            array_keys( $excluded_term_ids ),
            $source_taxonomy,
            $post_type
        );
    }

    // ── Internal query helpers ───────────────────────────────────────────────

    /**
     * Query posts that satisfy ALL required term sets and have NONE of the excluded terms.
     *
     * Uses a series of INTERSECTs (or nested subqueries for MySQL 5.7 compat) to
     * find posts that have at least one term from each set, then subtracts excluded.
     *
     * @param array[] $required_term_sets  Array of int[] — each inner array is one group.
     * @param int[]   $excluded_term_ids
     * @param string  $taxonomy
     * @param string  $post_type
     * @return int[]
     */
    private function query_matching_posts(
        array $required_term_sets,
        array $excluded_term_ids,
        string $taxonomy,
        string $post_type
    ): array {
        global $wpdb;

        $tr  = $wpdb->term_relationships;
        $tt  = $wpdb->term_taxonomy;
        $p   = $wpdb->posts;

        // Start with posts of the correct type and publish status.
        // For each required group, build a subquery of matching object_ids.
        // We do this via sequential refinement: start from all matching posts
        // for group[0], then intersect with group[1], etc.

        // Initial candidate set: all published posts of the right type.
        $placeholders_set = $this->int_array_placeholders( $required_term_sets[0] );
        $current_sql = $wpdb->prepare(
            "SELECT DISTINCT r.object_id
               FROM `{$tr}` r
               JOIN `{$tt}` tx ON tx.term_taxonomy_id = r.term_taxonomy_id
               JOIN `{$p}` po ON po.ID = r.object_id
              WHERE tx.taxonomy = %s
                AND tx.term_id IN ({$placeholders_set})
                AND po.post_type = %s
                AND po.post_status = 'publish'",
            array_merge( [ $taxonomy ], $required_term_sets[0], [ $post_type ] )
        );

        // Intersect with subsequent required groups.
        for ( $i = 1; $i < count( $required_term_sets ); $i++ ) {
            $ph = $this->int_array_placeholders( $required_term_sets[ $i ] );
            $current_sql = $wpdb->prepare(
                "SELECT DISTINCT r2.object_id
                   FROM `{$tr}` r2
                   JOIN `{$tt}` tx2 ON tx2.term_taxonomy_id = r2.term_taxonomy_id
                  WHERE tx2.taxonomy = %s
                    AND tx2.term_id IN ({$ph})
                    AND r2.object_id IN ({$current_sql})",
                array_merge( [ $taxonomy ], $required_term_sets[ $i ] )
            );
        }

        // Exclude posts that have any excluded term.
        if ( ! empty( $excluded_term_ids ) ) {
            $ex_ph = $this->int_array_placeholders( $excluded_term_ids );
            $current_sql = $wpdb->prepare(
                "SELECT object_id FROM ({$current_sql}) base_q
                  WHERE object_id NOT IN (
                      SELECT DISTINCT r3.object_id
                        FROM `{$tr}` r3
                        JOIN `{$tt}` tx3 ON tx3.term_taxonomy_id = r3.term_taxonomy_id
                       WHERE tx3.taxonomy = %s
                         AND tx3.term_id IN ({$ex_ph})
                  )",
                array_merge( [ $taxonomy ], $excluded_term_ids )
            );
        }

        $results = $wpdb->get_col( $current_sql );
        return array_map( 'intval', $results ?: [] );
    }

    /**
     * Return the term IDs currently assigned to a post in a given taxonomy.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return int[]
     */
    public function get_object_term_ids( int $post_id, string $taxonomy ): array {
        $terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }
        return array_map( 'intval', $terms );
    }

    /**
     * Build a comma-separated %d placeholder string for a given int array.
     *
     * @param int[] $ids
     * @return string
     */
    private function int_array_placeholders( array $ids ): string {
        return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
    }
}
