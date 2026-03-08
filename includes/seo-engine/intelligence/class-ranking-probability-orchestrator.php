<?php
/**
 * RankingProbabilityOrchestrator — assembles all 7 real signals for a post
 * and feeds them into the RankingProbabilityEngine.
 *
 * Signals:
 *   1. intent_match             — from KeywordIntent classifier
 *   2. topical_authority        — from TopicalAuthorityEngine or cluster scores
 *   3. cluster_coverage         — from ClusterScoringEngine
 *   4. content_depth            — from QualityScoreEngine (word count + score)
 *   5. internal_linking_strength— count of inbound internal links
 *   6. competitor_weakness      — from SerpWeaknessEngine (cached)
 *   7. keyword_difficulty       — from DataForSEO KD
 *
 * Bonus signals (if GSC connected):
 *   - GSC CTR and position → modulates final score
 *   - GSC impressions → adjusts keyword_difficulty estimate
 *
 * @package TMWSEO\Engine\Intelligence
 */
namespace TMWSEO\Engine\Intelligence;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Keywords\KDFilter;
use TMWSEO\Engine\Logs;

class RankingProbabilityOrchestrator {

    /**
     * Runs the full signal assembly for a post and calculates ranking probability.
     *
     * @return array{ok:bool, ranking_probability:float, ranking_tier:string, inputs:array, signals_detail:array}
     */
    public static function run_for_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [ 'ok' => false, 'error' => 'invalid_post' ];
        }

        $post_type     = (string) $post->post_type;
        $focus_keyword = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( $focus_keyword === '' ) {
            $focus_keyword = trim( strip_tags( $post->post_title ) );
        }

        $cache_key = 'tmwseo_rpo_' . $post_id . '_' . md5( $focus_keyword );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $detail = [];

        // ── 1. Intent match ────────────────────────────────────────────────
        $intent_match = self::score_intent_match( $post_id, $focus_keyword, $detail );

        // ── 2. Topical authority ───────────────────────────────────────────
        $topical_authority = self::score_topical_authority( $post_id, $detail );

        // ── 3. Cluster coverage ────────────────────────────────────────────
        $cluster_coverage = self::score_cluster_coverage( $post_id, $detail );

        // ── 4. Content depth ───────────────────────────────────────────────
        $content_depth = self::score_content_depth( $post_id, $detail );

        // ── 5. Internal linking strength ───────────────────────────────────
        $internal_linking = self::score_internal_linking( $post_id, $detail );

        // ── 6. Competitor weakness ─────────────────────────────────────────
        $competitor_weakness = self::score_competitor_weakness( $focus_keyword, $detail );

        // ── 7. Keyword difficulty ──────────────────────────────────────────
        $keyword_difficulty = self::score_keyword_difficulty( $focus_keyword, $detail );

        $inputs = [
            'intent_match'              => $intent_match,
            'topical_authority'         => $topical_authority,
            'cluster_coverage'          => $cluster_coverage,
            'content_depth'             => $content_depth,
            'internal_linking_strength' => $internal_linking,
            'competitor_weakness'       => $competitor_weakness,
            'keyword_difficulty'        => $keyword_difficulty,
        ];

        // ── GSC bonus modifier ─────────────────────────────────────────────
        $gsc_modifier = self::get_gsc_modifier( $post_id, $detail );

        // ── Model-specific bonus (platform coverage + taxonomy richness) ───
        $model_bonus = self::get_model_platform_bonus( $post_id, $post_type, $detail );

        // Calculate base probability
        $engine = new RankingProbabilityEngine();
        $result = $engine->calculate( $focus_keyword, $inputs );

        // Apply GSC modifier (±10 points max) + model bonus (0–8 for model pages)
        $final_prob = max( 0, min( 100, $result['ranking_probability'] + $gsc_modifier + $model_bonus ) );
        $final_prob = round( $final_prob, 2 );

        $output = [
            'ok'                 => true,
            'post_id'            => $post_id,
            'keyword'            => $focus_keyword,
            'ranking_probability'=> $final_prob,
            'ranking_tier'       => $engine->tier( $final_prob ),
            'inputs'             => $inputs,
            'gsc_modifier'       => $gsc_modifier,
            'signals_detail'     => $detail,
            'calculated_at'      => current_time( 'mysql' ),
        ];

        // Store result back to post meta for quick display
        update_post_meta( $post_id, '_tmwseo_ranking_probability', $final_prob );
        update_post_meta( $post_id, '_tmwseo_ranking_tier', $engine->tier( $final_prob ) );
        update_post_meta( $post_id, '_tmwseo_ranking_probability_at', current_time( 'mysql' ) );

        set_transient( $cache_key, $output, 6 * HOUR_IN_SECONDS );

        Logs::info( 'intelligence', '[RPO] Ranking probability assembled', [
            'post_id'     => $post_id,
            'keyword'     => $focus_keyword,
            'probability' => $final_prob,
            'tier'        => $engine->tier( $final_prob ),
        ] );

        return $output;
    }

    // ── Signal scorers ─────────────────────────────────────────────────────

    private static function score_intent_match( int $post_id, string $keyword, array &$detail ): float {
        // Check if secondary keywords mention intent signals
        $secondary = (string) get_post_meta( $post_id, 'rank_math_secondary_keywords', true );
        $score     = 50.0; // neutral baseline

        if ( $keyword !== '' ) {
            // Model pages: if keyword contains model name, intent is strong
            $title = strtolower( strip_tags( (string) get_the_title( $post_id ) ) );
            $kw    = strtolower( $keyword );
            if ( strpos( $kw, $title ) !== false || strpos( $title, $kw ) !== false ) {
                $score = 80.0;
            }
            // Navigational intent (contains cam/live/stream) scores well for cam sites
            if ( preg_match( '/\b(cam|live|stream|watch|webcam)\b/', $kw ) ) {
                $score = min( 100, $score + 15 );
            }
        }

        $detail['intent_match'] = [ 'score' => $score, 'method' => 'keyword_title_match' ];
        return $score;
    }

    private static function score_topical_authority( int $post_id, array &$detail ): float {
        // Count posts in same category/tag taxonomy cluster
        $tags = wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'slugs' ] );
        if ( is_wp_error( $tags ) ) $tags = [];

        $related_count = 0;
        foreach ( (array) $tags as $tag ) {
            $term  = get_term_by( 'slug', (string) $tag, 'post_tag' );
            if ( $term instanceof \WP_Term ) {
                $related_count += max( 0, $term->count - 1 );
            }
        }

        // 0 related → 0, 50+ related → 100
        $score = min( 100, ( $related_count / max( 1, 50 ) ) * 100 );

        $detail['topical_authority'] = [ 'score' => $score, 'related_posts' => $related_count ];
        return round( $score, 1 );
    }

    private static function score_cluster_coverage( int $post_id, array &$detail ): float {
        global $wpdb;
        // Pull cluster score from DB if available
        $cluster_scores_table = $wpdb->prefix . 'tmwseo_cluster_scores';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cluster_scores_table}'" ) !== $cluster_scores_table ) {
            $detail['cluster_coverage'] = [ 'score' => 50, 'source' => 'table_missing' ];
            return 50.0;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT coverage_score FROM {$cluster_scores_table} WHERE post_id = %d ORDER BY scored_at DESC LIMIT 1", $post_id )
        );

        $score = $row ? (float) $row->coverage_score : 40.0;
        $detail['cluster_coverage'] = [ 'score' => $score, 'source' => 'cluster_scores_table' ];
        return $score;
    }

    private static function score_content_depth( int $post_id, array &$detail ): float {
        $content   = (string) get_post_field( 'post_content', $post_id );
        $word_count = str_word_count( strip_tags( $content ) );

        // 300 words = 30, 1000 words = 80, 2000+ words = 100
        if ( $word_count >= 2000 ) {
            $score = 100.0;
        } elseif ( $word_count >= 300 ) {
            $score = 30.0 + ( ( $word_count - 300 ) / 1700 ) * 70.0;
        } else {
            $score = max( 0, ( $word_count / 300 ) * 30 );
        }

        $detail['content_depth'] = [ 'score' => round( $score, 1 ), 'word_count' => $word_count ];
        return round( $score, 1 );
    }

    private static function score_internal_linking( int $post_id, array &$detail ): float {
        global $wpdb;
        // Count posts whose content links to this post's permalink
        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) {
            $detail['internal_linking_strength'] = [ 'score' => 0, 'inbound_count' => 0 ];
            return 0.0;
        }

        $slug = rtrim( parse_url( $permalink, PHP_URL_PATH ) ?? '', '/' );
        if ( $slug === '' ) {
            $detail['internal_linking_strength'] = [ 'score' => 0, 'inbound_count' => 0 ];
            return 0.0;
        }

        $count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_content LIKE %s", '%' . $wpdb->esc_like( $slug ) . '%' )
        );
        $count = max( 0, $count - 1 ); // exclude self

        // 0 links = 0, 10+ links = 100
        $score = min( 100, ( $count / 10 ) * 100 );
        $detail['internal_linking_strength'] = [ 'score' => round( $score, 1 ), 'inbound_count' => $count ];
        return round( $score, 1 );
    }

    private static function score_competitor_weakness( string $keyword, array &$detail ): float {
        global $wpdb;
        if ( $keyword === '' ) {
            $detail['competitor_weakness'] = [ 'score' => 50, 'source' => 'no_keyword' ];
            return 50.0;
        }

        // Pull from persisted serp_analysis table
        $table = $wpdb->prefix . 'tmwseo_serp_analysis';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            $detail['competitor_weakness'] = [ 'score' => 50, 'source' => 'table_missing' ];
            return 50.0;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT serp_weakness_score FROM {$table} WHERE keyword = %s ORDER BY created_at DESC LIMIT 1", strtolower( $keyword ) )
        );

        if ( ! $row ) {
            $detail['competitor_weakness'] = [ 'score' => 50, 'source' => 'no_data' ];
            return 50.0;
        }

        // serp_weakness_score is 1–10, convert to 0–100 for probability engine
        $score = ( (float) $row->serp_weakness_score / 10 ) * 100;
        $detail['competitor_weakness'] = [ 'score' => round( $score, 1 ), 'weakness_score_raw' => $row->serp_weakness_score ];
        return round( $score, 1 );
    }

    private static function score_keyword_difficulty( string $keyword, array &$detail ): float {
        if ( $keyword === '' ) {
            $detail['keyword_difficulty'] = [ 'score' => 50, 'source' => 'no_keyword' ];
            return 50.0;
        }

        $cache_key = 'tmwseo_kd_' . md5( strtolower( $keyword ) );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            $detail['keyword_difficulty'] = [ 'score' => (float) $cached, 'source' => 'cache' ];
            return (float) $cached;
        }

        if ( ! DataForSEO::is_configured() ) {
            $detail['keyword_difficulty'] = [ 'score' => 50, 'source' => 'dfs_not_configured' ];
            return 50.0;
        }

        $res = DataForSEO::bulk_keyword_difficulty( [ $keyword ] );
        $kd  = 50.0;
        if ( ( $res['ok'] ?? false ) && isset( $res['map'][ strtolower( $keyword ) ] ) ) {
            $kd = (float) $res['map'][ strtolower( $keyword ) ];
        }

        set_transient( $cache_key, $kd, DAY_IN_SECONDS );
        $detail['keyword_difficulty'] = [ 'score' => $kd, 'source' => 'dataforseo' ];
        return $kd;
    }

    private static function get_gsc_modifier( int $post_id, array &$detail ): float {
        if ( ! GSCApi::is_connected() ) {
            $detail['gsc'] = [ 'modifier' => 0, 'source' => 'not_connected' ];
            return 0.0;
        }

        $permalink = (string) get_permalink( $post_id );
        if ( ! $permalink ) return 0.0;

        $metrics = GSCApi::page_metrics( $permalink );
        if ( ! $metrics['ok'] ) {
            $detail['gsc'] = [ 'modifier' => 0, 'source' => 'no_data' ];
            return 0.0;
        }

        $modifier = 0.0;

        // Good CTR (>5%) = +5 points
        if ( $metrics['ctr'] >= 5 ) $modifier += 5;
        // Position 1-10 = +5, 11-20 = +2, >30 = -5
        if ( $metrics['position'] > 0 && $metrics['position'] <= 10 ) $modifier += 5;
        elseif ( $metrics['position'] <= 20 ) $modifier += 2;
        elseif ( $metrics['position'] > 30 ) $modifier -= 5;
        // High impressions = +0 to +5 extra
        if ( $metrics['impressions'] > 1000 ) $modifier += min( 5, ( $metrics['impressions'] / 10000 ) * 5 );

        $detail['gsc'] = array_merge( $metrics, [ 'modifier' => round( $modifier, 1 ) ] );
        return round( $modifier, 1 );
    }

    /**
     * Model-specific bonus signal: platform coverage.
     *
     * Model pages with more platform data have stronger cross-platform relevance,
     * which boosts their ranking potential for navigational/platform-intent keywords.
     * Returns a modifier of 0–8 points added to the final probability.
     */
    private static function get_model_platform_bonus( int $post_id, string $post_type, array &$detail ): float {
        if ( $post_type !== 'model' ) {
            $detail['model_platform'] = [ 'modifier' => 0, 'reason' => 'not_model_page' ];
            return 0.0;
        }

        // Lazy-load ModelIntelligence (avoid circular deps at boot time)
        if ( ! class_exists( '\\TMWSEO\\Engine\\Model\\ModelIntelligence' ) ) {
            $detail['model_platform'] = [ 'modifier' => 0, 'reason' => 'service_unavailable' ];
            return 0.0;
        }

        $platform_score  = \TMWSEO\Engine\Model\ModelIntelligence::platform_coverage_score( $post_id );
        $taxonomy_score  = \TMWSEO\Engine\Model\ModelIntelligence::taxonomy_richness_score( $post_id );

        // Platform coverage: 0–5 bonus points
        $platform_bonus  = round( ( $platform_score / 100 ) * 5, 1 );
        // Taxonomy richness: 0–3 bonus points
        $taxonomy_bonus  = round( ( $taxonomy_score / 100 ) * 3, 1 );

        $modifier = $platform_bonus + $taxonomy_bonus;
        $detail['model_platform'] = [
            'modifier'        => $modifier,
            'platform_score'  => $platform_score,
            'taxonomy_score'  => $taxonomy_score,
            'platform_bonus'  => $platform_bonus,
            'taxonomy_bonus'  => $taxonomy_bonus,
        ];

        return $modifier;
    }

    /**
     * Run the full probability scan for all published model and category pages.
     * Stores results in post meta for fast display in admin surfaces.
     * Does NOT publish anything. Read and compute only.
     *
     * @return array{ok:bool, processed:int, errors:int}
     */
    public static function run_all(): array {
        $post_types = apply_filters( 'tmwseo_rpo_post_types', [ 'model', 'tmw_category_page', 'post' ] );
        $post_ids   = get_posts( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $processed = 0;
        $errors    = 0;

        foreach ( $post_ids as $post_id ) {
            try {
                // Clear cached result so fresh signals are computed
                $focus_keyword = (string) get_post_meta( (int) $post_id, 'rank_math_focus_keyword', true );
                delete_transient( 'tmwseo_rpo_' . $post_id . '_' . md5( $focus_keyword ) );

                $result = self::run_for_post( (int) $post_id );
                if ( ! empty( $result['ok'] ) ) {
                    $processed++;
                    // Also store in the intelligence table if available
                    self::store_intelligence_record( (int) $post_id, $result );
                } else {
                    $errors++;
                }
            } catch ( \Throwable $e ) {
                $errors++;
                Logs::info( 'intelligence', '[RPO] run_all error for post ' . $post_id . ': ' . $e->getMessage() );
            }
        }

        update_option( 'tmwseo_rpo_last_run', current_time( 'mysql' ), false );

        return [ 'ok' => true, 'processed' => $processed, 'errors' => $errors ];
    }

    private static function store_intelligence_record( int $post_id, array $result ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_intelligence';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return;
        }

        $prob = (float) ( $result['ranking_probability'] ?? 0 );

        $wpdb->replace( $table, [
            'post_id'      => $post_id,
            'signal_type'  => 'ranking_probability',
            'signal_value' => (string) round( $prob / 100, 4 ),
            'computed_at'  => current_time( 'mysql' ),
        ] );
    }
}

