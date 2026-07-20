<?php
/**
 * TMW SEO Engine — Model Intelligence Service
 *
 * Unified read-only API for model entity data.
 * Provides a single, coherent view of what makes a model page valuable
 * for SEO purposes across all admin surfaces.
 *
 * Nothing in this class publishes, modifies, or schedules content.
 * All data is derived from existing post meta, taxonomies, and engine tables.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.2.1
 */
namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Integrations\GSCApi;

class ModelIntelligence {

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Get a full intelligence snapshot for a model post.
     *
     * @return array{
     *   post_id: int,
     *   name: string,
     *   status: string,
     *   permalink: string,
     *   focus_keyword: string,
     *   primary_platform: string,
     *   platform_slugs: string[],
     *   platform_labels: string[],
     *   platform_count: int,
     *   categories: string[],
     *   tags: string[],
     *   taxonomy_score: int,
     *   ranking_probability: float,
     *   ranking_tier: string,
     *   inbound_links: int,
     *   word_count: int,
     *   content_score: int,
     *   has_meta_desc: bool,
     *   has_schema: bool,
     *   optimizer_run_at: string,
     *   review_state: string,
     *   readiness_score: int,
     *   readiness_issues: string[],
     *   opportunity_signals: string[],
     * }
     */
    public static function get( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) || $post->post_type !== 'model' ) {
            return self::empty_snapshot( $post_id );
        }

        $name              = trim( (string) $post->post_title );
        $focus_keyword     = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        $meta_desc         = (string) get_post_meta( $post_id, 'rank_math_description', true );
        $ranking_prob      = (float) get_post_meta( $post_id, '_tmwseo_ranking_probability', true );
        $ranking_tier      = (string) get_post_meta( $post_id, '_tmwseo_ranking_tier', true );
        $optimizer_run_at  = (string) get_post_meta( $post_id, '_tmwseo_model_optimizer_suggestions', true );
        if ( $optimizer_run_at !== '' ) {
            $d = json_decode( $optimizer_run_at, true );
            $optimizer_run_at = is_array( $d ) ? (string) ( $d['generated_at'] ?? '' ) : '';
        }

        // Platforms
        $platform_links    = PlatformProfiles::get_links( $post_id );
        $primary_platform  = (string) get_post_meta( $post_id, '_tmwseo_platform_primary', true );
        $platform_slugs    = [];
        $platform_labels   = [];
        foreach ( $platform_links as $r ) {
            $slug = sanitize_key( (string) ( $r['platform'] ?? '' ) );
            if ( $slug === '' ) { continue; }
            $platform_slugs[] = $slug;
            $p = PlatformRegistry::get( $slug );
            $platform_labels[] = $p ? (string) $p['name'] : ucfirst( $slug );
        }
        if ( $primary_platform !== '' && ! in_array( $primary_platform, $platform_slugs, true ) ) {
            $platform_slugs[]  = $primary_platform;
            $p = PlatformRegistry::get( $primary_platform );
            $platform_labels[] = $p ? (string) $p['name'] : ucfirst( $primary_platform );
        }
        $platform_slugs  = array_unique( $platform_slugs );
        $platform_labels = array_unique( $platform_labels );

        // Taxonomy
        $categories = self::get_term_names( $post_id, 'category' );
        $tags       = self::get_term_names( $post_id, 'post_tag' );
        $all_tax    = array_merge( $categories, $tags );
        $taxonomy_score = min( 100, count( $all_tax ) * 8 ); // 12+ terms = full score

        // Content
        $content    = (string) get_post_field( 'post_content', $post_id );
        $word_count = str_word_count( strip_tags( $content ) );
        $content_score = $word_count >= 800 ? 100 : (int) round( ( $word_count / 800 ) * 100 );

        // Internal links (inbound)
        $inbound_links = self::count_inbound_links( $post_id );

        // Review state — from most recent draft linked to this model
        $review_state = self::get_model_review_state( $post_id );

        // Readiness assessment
        [ $readiness_score, $readiness_issues ] = self::assess_readiness(
            $name, $focus_keyword, $meta_desc, $platform_slugs, $categories, $tags,
            $word_count, $inbound_links, $ranking_prob
        );

        // Opportunity signals
        $opportunity_signals = self::detect_opportunities(
            $post_id, $platform_slugs, $categories, $tags, $word_count, $inbound_links, $ranking_prob
        );

        return [
            'post_id'              => $post_id,
            'name'                 => $name,
            'status'               => (string) $post->post_status,
            'permalink'            => (string) get_permalink( $post_id ),
            'focus_keyword'        => $focus_keyword,
            'primary_platform'     => $primary_platform,
            'platform_slugs'       => array_values( $platform_slugs ),
            'platform_labels'      => array_values( $platform_labels ),
            'platform_count'       => count( $platform_slugs ),
            'categories'           => $categories,
            'tags'                 => $tags,
            'taxonomy_score'       => $taxonomy_score,
            'ranking_probability'  => $ranking_prob,
            'ranking_tier'         => $ranking_tier,
            'inbound_links'        => $inbound_links,
            'word_count'           => $word_count,
            'content_score'        => $content_score,
            'has_meta_desc'        => $meta_desc !== '',
            'has_schema'           => (bool) get_post_meta( $post_id, '_tmwseo_schema_done', true ),
            'optimizer_run_at'     => $optimizer_run_at,
            'review_state'         => $review_state,
            'readiness_score'      => $readiness_score,
            'readiness_issues'     => $readiness_issues,
            'opportunity_signals'  => $opportunity_signals,
        ];
    }

    /**
     * Get a compact summary list for all model pages (used by Command Center).
     * Returns only enough data to populate dashboard widgets. Cached for 5 minutes.
     *
     * @return array<int, array{
     *   post_id:int, name:string, status:string, ranking_probability:float, ranking_tier:string,
     *   platform_count:int, platform_labels:string[], inbound_links:int, word_count:int,
     *   taxonomy_score:int, readiness_score:int, readiness_issues:string[],
     *   opportunity_signals:string[], review_state:string,
     * }>
     */
    public static function get_all_summaries( bool $use_cache = true ): array {
        $cache_key = 'tmwseo_model_intel_summaries_v1';
        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $models = get_posts( [
            'post_type'      => 'model',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $summaries = [];
        foreach ( $models as $post_id ) {
            $d = self::get( (int) $post_id );
            $summaries[] = [
                'post_id'             => $d['post_id'],
                'name'                => $d['name'],
                'status'              => $d['status'],
                'ranking_probability' => $d['ranking_probability'],
                'ranking_tier'        => $d['ranking_tier'],
                'platform_count'      => $d['platform_count'],
                'platform_labels'     => $d['platform_labels'],
                'inbound_links'       => $d['inbound_links'],
                'word_count'          => $d['word_count'],
                'taxonomy_score'      => $d['taxonomy_score'],
                'readiness_score'     => $d['readiness_score'],
                'readiness_issues'    => $d['readiness_issues'],
                'opportunity_signals' => $d['opportunity_signals'],
                'review_state'        => $d['review_state'],
            ];
        }

        // Sort by readiness score desc (most ready first)
        usort( $summaries, fn( $a, $b ) => $b['readiness_score'] - $a['readiness_score'] );

        set_transient( $cache_key, $summaries, 5 * MINUTE_IN_SECONDS );

        return $summaries;
    }

    /**
     * Dashboard-level aggregate stats for all model pages.
     *
     * @return array{
     *   total: int,
     *   published: int,
     *   with_platform: int,
     *   multi_platform: int,
     *   missing_keyword: int,
     *   missing_meta: int,
     *   missing_platform: int,
     *   weak_content: int,
     *   no_inbound_links: int,
     *   with_ranking_prob: int,
     *   high_opportunity: int,
     *   avg_readiness: int,
     *   review_not_reviewed: int,
     *   review_in_review: int,
     *   review_needs_changes: int,
     *   top_opportunities: array,
     * }
     */
    public static function aggregate_stats(): array {
        $cache_key = 'tmwseo_model_intel_agg_v1';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $summaries = self::get_all_summaries();

        $stats = [
            'total'               => count( $summaries ),
            'published'           => 0,
            'with_platform'       => 0,
            'multi_platform'      => 0,
            'missing_keyword'     => 0,
            'missing_meta'        => 0,
            'missing_platform'    => 0,
            'weak_content'        => 0,
            'no_inbound_links'    => 0,
            'with_ranking_prob'   => 0,
            'high_opportunity'    => 0,
            'avg_readiness'       => 0,
            'review_not_reviewed' => 0,
            'review_in_review'    => 0,
            'review_needs_changes'=> 0,
            'top_opportunities'   => [],
        ];

        $readiness_sum = 0;

        foreach ( $summaries as $s ) {
            if ( $s['status'] === 'publish' )            { $stats['published']++; }
            if ( $s['platform_count'] > 0 )              { $stats['with_platform']++; }
            if ( $s['platform_count'] > 1 )              { $stats['multi_platform']++; }
            if ( $s['platform_count'] === 0 )            { $stats['missing_platform']++; }
            if ( $s['ranking_probability'] > 0 )         { $stats['with_ranking_prob']++; }
            if ( $s['ranking_probability'] >= 65 )       { $stats['high_opportunity']++; }
            if ( $s['word_count'] < 400 )                { $stats['weak_content']++; }
            if ( $s['inbound_links'] === 0 )             { $stats['no_inbound_links']++; }
            if ( $s['review_state'] === 'not_reviewed' ) { $stats['review_not_reviewed']++; }
            if ( $s['review_state'] === 'in_review' )    { $stats['review_in_review']++; }
            if ( $s['review_state'] === 'needs_changes' ){ $stats['review_needs_changes']++; }

            foreach ( $s['readiness_issues'] as $issue ) {
                if ( $issue === 'missing_keyword' )   { $stats['missing_keyword']++; }
                if ( $issue === 'missing_meta_desc' ) { $stats['missing_meta']++; }
            }

            $readiness_sum += $s['readiness_score'];
        }

        $stats['avg_readiness'] = count( $summaries ) > 0
            ? (int) round( $readiness_sum / count( $summaries ) )
            : 0;

        // Top 5 high-opportunity models (highest ranking_probability, published)
        $pub_sorted = array_filter( $summaries, fn( $s ) => $s['status'] === 'publish' && $s['ranking_probability'] > 0 );
        usort( $pub_sorted, fn( $a, $b ) => (int) ( $b['ranking_probability'] * 100 ) - (int) ( $a['ranking_probability'] * 100 ) );
        $stats['top_opportunities'] = array_slice( array_values( $pub_sorted ), 0, 5 );

        set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

        return $stats;
    }

    /**
     * Get platform coverage score for a model post (0–100).
     * Used as a bonus signal in RankingProbabilityOrchestrator.
     */
    public static function platform_coverage_score( int $post_id ): float {
        $links = PlatformProfiles::get_links( $post_id );
        $count = count( $links );
        // 0 platforms = 0, 1 = 40, 2 = 65, 3 = 80, 4+ = 95
        if ( $count === 0 ) return 0.0;
        if ( $count === 1 ) return 40.0;
        if ( $count === 2 ) return 65.0;
        if ( $count === 3 ) return 80.0;
        return 95.0;
    }

    /**
     * Get taxonomy richness score for a model post (0–100).
     * More categories + tags = higher authority signal.
     */
    public static function taxonomy_richness_score( int $post_id ): float {
        $cats  = self::get_term_names( $post_id, 'category' );
        $tags  = self::get_term_names( $post_id, 'post_tag' );
        $count = count( $cats ) + count( $tags );
        // 0 = 0, 3 = 30, 6 = 60, 10+ = 100
        return min( 100.0, (float) $count * 10 );
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /** @return string[] */
    private static function get_term_names( int $post_id, string $taxonomy ): array {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! is_array( $terms ) ) { return []; }
        $out = [];
        foreach ( $terms as $t ) {
            if ( $t instanceof \WP_Term ) {
                $out[] = (string) $t->name;
            }
        }
        return $out;
    }

    private static function count_inbound_links( int $post_id ): int {
        global $wpdb;
        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) { return 0; }
        $slug = rtrim( parse_url( $permalink, PHP_URL_PATH ) ?? '', '/' );
        if ( $slug === '' ) { return 0; }
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_content LIKE %s",
                '%' . $wpdb->esc_like( $slug ) . '%'
            )
        );
        return max( 0, $count - 1 );
    }

    private static function get_model_review_state( int $post_id ): string {
        // Check if any linked draft has a review state
        global $wpdb;
        $draft_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_tmwseo_linked_model_id' AND pm.meta_value = %s
                   AND p.post_status = 'draft'
                 LIMIT 1",
                (string) $post_id
            )
        );

        if ( $draft_id > 0 ) {
            $rs = (string) get_post_meta( $draft_id, '_tmwseo_review_state', true );
            return $rs !== '' ? $rs : 'not_reviewed';
        }

        return '';
    }

    /**
     * @param string[] $platform_slugs
     * @param string[] $categories
     * @param string[] $tags
     * @return array{0: int, 1: string[]}
     */
    private static function assess_readiness(
        string $name, string $focus_keyword, string $meta_desc,
        array $platform_slugs, array $categories, array $tags,
        int $word_count, int $inbound_links, float $ranking_prob
    ): array {
        $score  = 100;
        $issues = [];

        if ( $focus_keyword === '' ) {
            $score -= 20; $issues[] = 'missing_keyword';
        }
        if ( $meta_desc === '' ) {
            $score -= 15; $issues[] = 'missing_meta_desc';
        }
        if ( empty( $platform_slugs ) ) {
            $score -= 20; $issues[] = 'no_platform_data';
        }
        if ( count( $categories ) + count( $tags ) < 3 ) {
            $score -= 15; $issues[] = 'thin_taxonomy';
        }
        if ( $word_count < 300 ) {
            $score -= 20; $issues[] = 'thin_content';
        } elseif ( $word_count < 600 ) {
            $score -= 10; $issues[] = 'moderate_content';
        }
        if ( $inbound_links === 0 ) {
            $score -= 10; $issues[] = 'no_inbound_links';
        }

        return [ max( 0, $score ), $issues ];
    }

    /**
     * @param string[] $platform_slugs
     * @param string[] $categories
     * @param string[] $tags
     * @return string[]
     */
    private static function detect_opportunities(
        int $post_id, array $platform_slugs, array $categories, array $tags,
        int $word_count, int $inbound_links, float $ranking_prob
    ): array {
        $signals = [];

        if ( count( $platform_slugs ) >= 2 ) {
            $signals[] = 'multi_platform_opportunity';
        }
        if ( count( $categories ) + count( $tags ) >= 5 ) {
            $signals[] = 'rich_taxonomy_opportunity';
        }
        if ( $ranking_prob >= 65 ) {
            $signals[] = 'high_ranking_probability';
        }
        if ( $inbound_links === 0 ) {
            $signals[] = 'internal_link_needed';
        }
        if ( $word_count < 400 ) {
            $signals[] = 'content_depth_needed';
        }
        if ( count( $platform_slugs ) === 0 ) {
            $signals[] = 'platform_data_missing';
        }
        if ( $word_count >= 600 && count( $platform_slugs ) >= 1 && $ranking_prob >= 50 ) {
            $signals[] = 'ready_for_review';
        }

        return $signals;
    }

    private static function empty_snapshot( int $post_id ): array {
        return [
            'post_id'              => $post_id,
            'name'                 => '',
            'status'               => '',
            'permalink'            => '',
            'focus_keyword'        => '',
            'primary_platform'     => '',
            'platform_slugs'       => [],
            'platform_labels'      => [],
            'platform_count'       => 0,
            'categories'           => [],
            'tags'                 => [],
            'taxonomy_score'       => 0,
            'ranking_probability'  => 0.0,
            'ranking_tier'         => '',
            'inbound_links'        => 0,
            'word_count'           => 0,
            'content_score'        => 0,
            'has_meta_desc'        => false,
            'has_schema'           => false,
            'optimizer_run_at'     => '',
            'review_state'         => '',
            'readiness_score'      => 0,
            'readiness_issues'     => [],
            'opportunity_signals'  => [],
        ];
    }
}
