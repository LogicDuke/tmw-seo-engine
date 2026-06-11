<?php
/**
 * IndexReadinessGate — engine-side readiness and noindex control.
 *
 * Tags are noindex by default unless qualified.
 * Models/videos are noindex when quality/uniqueness/confidence gates fail.
 * Videos are noindex when title is still original import.
 *
 * Audit fix: previously, tag pages had zero quality control for indexation,
 * and model/video readiness gates were not explicit.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.4.0
 */
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\TagQualityEngine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class IndexReadinessGate {

    /** Post meta key storing engine readiness decision. */
    public const META_READY     = '_tmwseo_ready_to_index';
    public const META_GATE_LOG  = '_tmwseo_gate_log';

    /** Thresholds per page type. */
    private const THRESHOLDS = [
        'model' => [
            'min_quality'    => 60,
            'min_uniqueness' => 50,
            'min_confidence' => 40,
            'require_platform' => true,
        ],
        'post' => [ // video posts
            'min_quality'    => 45,
            'min_uniqueness' => 40,
            'min_confidence' => 25,
            'require_rewritten_title' => true,
        ],
        'tmw_category_page' => [
            'min_quality'    => 50,
            'min_uniqueness' => 40,
            'min_confidence' => 30,
        ],
    ];

    /** Tag readiness thresholds. */
    private const TAG_MIN_POSTS         = 8;
    private const TAG_MIN_QUALITY_SCORE = 50.0;

    public static function init(): void {
        // Hook into wp_head to inject noindex meta when appropriate.
        add_action( 'wp_head', [ __CLASS__, 'maybe_inject_noindex' ], 2 );

        // Hook into Rank Math robots filter if available.
        add_filter( 'rank_math/frontend/robots', [ __CLASS__, 'filter_rank_math_robots' ], 20 );
    }

    /**
     * Evaluate readiness for a specific post.
     *
     * @return array{ready:bool, reasons:string[], gate_details:array}
     */
    public static function evaluate_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [ 'ready' => false, 'reasons' => [ 'post_not_found' ], 'gate_details' => [] ];
        }

        $post_type  = $post->post_type;
        $thresholds = self::THRESHOLDS[ $post_type ] ?? self::THRESHOLDS['post'];

        $quality    = (int) get_post_meta( $post_id, '_tmwseo_content_quality_score', true );
        $uniqueness = (float) get_post_meta( $post_id, '_tmwseo_uniqueness_score', true );
        $confidence = (float) get_post_meta( $post_id, '_tmwseo_keyword_confidence', true );
        $primary_kw = trim( (string) get_post_meta( $post_id, '_tmwseo_keyword', true ) );
        $approval   = (string) get_post_meta( $post_id, '_tmwseo_approval_status', true );

        $reasons = [];
        $details = [
            'quality'       => $quality,
            'uniqueness'    => $uniqueness,
            'confidence'    => $confidence,
            'primary_kw'    => $primary_kw,
            'approval'      => $approval,
            'post_type'     => $post_type,
        ];

        // Gate: primary keyword must exist.
        if ( $primary_kw === '' ) {
            $reasons[] = 'missing_primary_keyword';
        }

        // Gate: quality score — fail-closed: score of 0 means not yet evaluated.
        $quality_threshold = (int) ( $thresholds['min_quality'] ?? 45 );
        if ( $quality === 0 ) {
            $reasons[] = 'quality_not_evaluated';
        } elseif ( $quality < $quality_threshold ) {
            $reasons[] = 'quality_below_threshold:' . $quality . '<' . $quality_threshold;
        }

        // Gate: uniqueness — Patch 2 fail-closed: missing uniqueness blocks readiness.
        $uniqueness_threshold = (float) ( $thresholds['min_uniqueness'] ?? 40 );
        $uniqueness_meta_raw = get_post_meta( $post_id, '_tmwseo_uniqueness_score', true );
        if ( $uniqueness_meta_raw === '' || $uniqueness_meta_raw === false ) {
            $reasons[] = 'uniqueness_not_evaluated';
        } elseif ( $uniqueness < $uniqueness_threshold ) {
            $reasons[] = 'uniqueness_below_threshold:' . round( $uniqueness ) . '<' . $uniqueness_threshold;
        }

        // Gate: keyword confidence — Patch 2 fail-closed: missing confidence blocks readiness.
        $confidence_threshold = (float) ( $thresholds['min_confidence'] ?? 25 );
        $confidence_meta_raw = get_post_meta( $post_id, '_tmwseo_keyword_confidence', true );
        if ( $confidence_meta_raw === '' || $confidence_meta_raw === false ) {
            $reasons[] = 'confidence_not_evaluated';
        } elseif ( $confidence < $confidence_threshold ) {
            $reasons[] = 'confidence_below_threshold:' . round( $confidence ) . '<' . $confidence_threshold;
        }

        // Gate: model must have at least one platform profile.
        if ( ! empty( $thresholds['require_platform'] ) && $post_type === 'model' ) {
            $has_platform = self::model_has_platform( $post_id );
            if ( ! $has_platform ) {
                $reasons[] = 'no_platform_profile';
            }
            $details['has_platform'] = $has_platform;
        }

        // Gate: video must have rewritten title.
        if ( ! empty( $thresholds['require_rewritten_title'] ) && $post_type === 'post' ) {
            $is_rewritten = (string) get_post_meta( $post_id, '_tmwseo_title_rewritten', true ) === '1';
            if ( ! $is_rewritten ) {
                $reasons[] = 'title_not_rewritten';
            }
            $details['title_rewritten'] = $is_rewritten;
        }

        // Gate: approval status.
        if ( $approval !== '' && $approval !== 'approved' ) {
            $reasons[] = 'not_approved:' . $approval;
        }

        $ready = empty( $reasons );

        // Persist decision.
        update_post_meta( $post_id, self::META_READY, $ready ? '1' : '0' );
        update_post_meta( $post_id, self::META_GATE_LOG, wp_json_encode( [
            'ready'   => $ready,
            'reasons' => $reasons,
            'details' => $details,
            'checked' => current_time( 'mysql' ),
        ] ) );

        return [
            'ready'        => $ready,
            'reasons'      => $reasons,
            'gate_details' => $details,
        ];
    }

    /**
     * Evaluate whether a tag term should be indexable.
     *
     * Tags are noindex by default unless they pass quality gates.
     */
    public static function is_tag_indexable( int $term_id ): bool {
        $term = get_term( $term_id, 'post_tag' );
        if ( ! $term instanceof \WP_Term ) {
            return false;
        }

        // Post count gate.
        if ( (int) $term->count < self::TAG_MIN_POSTS ) {
            return false;
        }

        // Quality score gate (if scored).
        if ( class_exists( '\\TMWSEO\\Engine\\Keywords\\TagQualityEngine' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tmw_tag_quality';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT quality_score, status, category_overlap, is_blocked FROM $table WHERE term_id = %d",
                    $term_id
                ), ARRAY_A );

                if ( $row ) {
                    if ( ! empty( $row['is_blocked'] ) ) return false;
                    if ( ! empty( $row['category_overlap'] ) ) return false;
                    if ( (float) ( $row['quality_score'] ?? 0 ) < self::TAG_MIN_QUALITY_SCORE ) return false;
                    if ( ! in_array( $row['status'], [ 'qualified', 'promoted' ], true ) ) return false;
                }
                // If not in table yet, default to not indexable.
                if ( ! $row ) return false;
            }
        }

        return true;
    }

    /**
     * Inject noindex meta tag in wp_head for pages that fail gates.
     *
     * Patch 2: covers both tag archives AND singular model/video posts.
     * This works independently of Rank Math — the engine's own safety net.
     */
    public static function maybe_inject_noindex(): void {
        // Tag archives.
        if ( is_tag() ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term && ! self::is_tag_indexable( $term->term_id ) ) {
                echo '<meta name="robots" content="noindex, follow">' . "\n";
            }
            return;
        }

        // Singular model/video posts — standalone noindex without Rank Math dependency.
        if ( is_singular( [ 'model', 'post' ] ) ) {
            $post_id = get_the_ID();
            if ( ! $post_id ) return;

            $ready = get_post_meta( $post_id, self::META_READY, true );
            // If explicitly evaluated and not ready, inject noindex.
            if ( $ready === '0' ) {
                echo '<meta name="robots" content="noindex, follow">' . "\n";
            }
        }
    }

    /**
     * Filter Rank Math robots meta for tag archives and weak posts.
     *
     * @param array $robots Rank Math robots array.
     * @return array
     */
    public static function filter_rank_math_robots( $robots ) {
        if ( ! is_array( $robots ) ) {
            $robots = [];
        }

        // Tag archive control.
        if ( is_tag() ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term && ! self::is_tag_indexable( $term->term_id ) ) {
                $robots['index'] = 'noindex';
            }
            return $robots;
        }

        // Singular post control.
        if ( is_singular() ) {
            $post_id = get_the_ID();
            if ( $post_id ) {
                $ready = get_post_meta( $post_id, self::META_READY, true );
                // If explicitly evaluated and not ready, noindex.
                if ( $ready === '0' ) {
                    $robots['index'] = 'noindex';
                }
            }
        }

        return $robots;
    }

    /**
     * Batch-evaluate readiness for all published posts of given types.
     *
     * @return array{evaluated:int, ready:int, not_ready:int}
     */
    public static function batch_evaluate( array $post_types = [ 'model', 'post' ], int $limit = 200 ): array {
        $posts = get_posts( [
            'post_type'      => $post_types,
            'post_status'    => [ 'publish', 'draft', 'pending' ],
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );

        $stats = [ 'evaluated' => 0, 'ready' => 0, 'not_ready' => 0 ];
        foreach ( $posts as $post_id ) {
            $result = self::evaluate_post( (int) $post_id );
            $stats['evaluated']++;
            if ( $result['ready'] ) {
                $stats['ready']++;
            } else {
                $stats['not_ready']++;
            }
        }

        return $stats;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function model_has_platform( int $post_id ): bool {
        $platforms = [
            'livejasmin', 'stripchat', 'chaturbate', 'myfreecams',
            'camsoda', 'bonga', 'cam4',
        ];

        foreach ( $platforms as $p ) {
            $username = trim( (string) get_post_meta( $post_id, '_tmwseo_platform_username_' . $p, true ) );
            if ( $username !== '' ) {
                return true;
            }
        }

        return false;
    }
}
