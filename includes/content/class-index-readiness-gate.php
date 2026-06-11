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

    /**
     * Manual approval meta for the controlled first indexing batch only.
     *
     * This does not mark a post ready and does not weaken readiness globally.
     * It only lets the audited first-batch model allowlist suppress this
     * class's noindex safety net when an operator has explicitly approved it.
     */
    public const META_CONTROLLED_BATCH_INDEX_APPROVED = '_tmwseo_controlled_batch_index_approved';

    /** First manually approved model slugs eligible for the narrow override. */
    private const CONTROLLED_BATCH_MODEL_SLUGS = [
        'abby-murray',
        'aisha-dupont',
        'alice-schuster',
        'allysa-quinn',
        'anisyia',
        'arianna',
        'brook-hayes',
        'hana-ross',
        'julieta-montesco',
        'lexy-ness',
        'mia-collie',
    ];

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

        // Safe operator action: sets only the controlled first-batch override meta.
        add_action( 'admin_post_tmwseo_apply_controlled_batch_index_override', [ __CLASS__, 'handle_controlled_batch_override_admin_action' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
            \WP_CLI::add_command( 'tmwseo controlled-batch-index-override', [ __CLASS__, 'wp_cli_apply_controlled_batch_override' ] );
        }
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
            // If explicitly evaluated and not ready, inject noindex unless this
            // published model has the controlled first-batch manual override.
            if ( $ready === '0' ) {
                if ( self::has_controlled_batch_override( (int) $post_id ) ) {
                    self::log_controlled_batch_override( (int) $post_id, 'standalone_noindex' );
                    return;
                }

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
                // If explicitly evaluated and not ready, noindex unless this
                // published model has the controlled first-batch manual override.
                if ( $ready === '0' ) {
                    if ( self::has_controlled_batch_override( (int) $post_id ) ) {
                        self::log_controlled_batch_override( (int) $post_id, 'rank_math_robots' );
                        return $robots;
                    }

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


    /**
     * Does this post have the controlled first indexing batch override?
     *
     * This override is intentionally narrow and auditable. It is not global
     * indexing approval: categories, category archives, tags, videos, filters,
     * unfinished models, and any model outside CONTROLLED_BATCH_MODEL_SLUGS
     * continue through the normal readiness/noindex gates.
     */
    public static function has_controlled_batch_override( int $post_id ): bool {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        if ( $post->post_type !== 'model' || $post->post_status !== 'publish' ) {
            return false;
        }

        if ( ! in_array( $post->post_name, self::CONTROLLED_BATCH_MODEL_SLUGS, true ) ) {
            return false;
        }

        return (string) get_post_meta( $post_id, self::META_CONTROLLED_BATCH_INDEX_APPROVED, true ) === '1';
    }

    /**
     * Set controlled first-batch override meta on allowlisted published models only.
     *
     * This intentionally does not update META_READY or META_GATE_LOG so readiness
     * diagnostics remain unchanged. It also refuses to set meta for any model not
     * in CONTROLLED_BATCH_MODEL_SLUGS.
     *
     * @return array{updated:int, already_set:int, missing:string[], skipped:array<string,string>}
     */
    public static function apply_controlled_batch_override(): array {
        $result = [
            'updated'     => 0,
            'already_set' => 0,
            'missing'     => [],
            'skipped'     => [],
        ];

        foreach ( self::CONTROLLED_BATCH_MODEL_SLUGS as $slug ) {
            $posts = get_posts( [
                'name'           => $slug,
                'post_type'      => 'model',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );

            if ( empty( $posts ) ) {
                $result['missing'][] = $slug;
                continue;
            }

            $post_id = (int) $posts[0];
            $post    = get_post( $post_id );
            if ( ! $post instanceof \WP_Post ) {
                $result['missing'][] = $slug;
                continue;
            }

            if ( $post->post_type !== 'model' || $post->post_name !== $slug ) {
                $result['skipped'][ $slug ] = 'not_allowlisted_model';
                continue;
            }

            if ( $post->post_status !== 'publish' ) {
                $result['skipped'][ $slug ] = 'not_published:' . $post->post_status;
                continue;
            }

            if ( (string) get_post_meta( $post_id, self::META_CONTROLLED_BATCH_INDEX_APPROVED, true ) === '1' ) {
                $result['already_set']++;
                continue;
            }

            update_post_meta( $post_id, self::META_CONTROLLED_BATCH_INDEX_APPROVED, '1' );
            $result['updated']++;

            error_log( sprintf(
                '[TMW-SEO-AUDIT] [TMW-INDEX-GATE] controlled first-batch index override meta set for model post_id=%d slug=%s',
                $post_id,
                $slug
            ) );
        }

        return $result;
    }

    /** Safe admin action for applying the controlled first-batch override meta. */
    public static function handle_controlled_batch_override_admin_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to apply controlled index overrides.', 'tmwseo' ), 403 );
        }

        check_admin_referer( 'tmwseo_apply_controlled_batch_index_override' );

        $result = self::apply_controlled_batch_override();
        $url    = add_query_arg( [
            'tmwseo_controlled_batch_override' => 'done',
            'updated'                          => (int) $result['updated'],
            'already_set'                      => (int) $result['already_set'],
            'missing'                          => count( $result['missing'] ),
            'skipped'                          => count( $result['skipped'] ),
        ], wp_get_referer() ?: admin_url() );

        wp_safe_redirect( $url );
        exit;
    }

    /** WP-CLI command callback for applying the controlled first-batch override meta. */
    public static function wp_cli_apply_controlled_batch_override( array $args, array $assoc_args ): void {
        unset( $args, $assoc_args );

        $result = self::apply_controlled_batch_override();

        \WP_CLI::log( sprintf(
            'Controlled first-batch index override complete: updated=%d already_set=%d missing=%d skipped=%d',
            (int) $result['updated'],
            (int) $result['already_set'],
            count( $result['missing'] ),
            count( $result['skipped'] )
        ) );

        if ( ! empty( $result['missing'] ) ) {
            \WP_CLI::warning( 'Missing allowlisted slugs: ' . implode( ', ', $result['missing'] ) );
        }

        foreach ( $result['skipped'] as $slug => $reason ) {
            \WP_CLI::warning( sprintf( 'Skipped %s: %s', $slug, $reason ) );
        }
    }

    /** Log when the controlled first-batch override suppresses this gate's noindex. */
    private static function log_controlled_batch_override( int $post_id, string $source ): void {
        $post = get_post( $post_id );
        $slug = $post instanceof \WP_Post ? $post->post_name : '';

        error_log( sprintf(
            '[TMW-INDEX-GATE] [TMW-NOINDEX-BLOCKER] controlled first-batch model override suppressed noindex source=%s post_id=%d slug=%s',
            $source,
            $post_id,
            $slug
        ) );
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
