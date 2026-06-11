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
     * Per-model manual approval meta for bypassing this gate's noindex output.
     *
     * This does not mark a post ready and does not weaken readiness globally.
     * Readiness diagnostics remain active through META_READY and META_GATE_LOG.
     */
    public const META_MANUAL_INDEX_APPROVED = '_tmwseo_manual_index_approved';

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

        // Admin-only model edit/list controls for reusable manual model approvals.
        add_action( 'add_meta_boxes_model', [ __CLASS__, 'register_manual_index_metabox' ] );
        add_action( 'save_post_model', [ __CLASS__, 'save_manual_index_metabox' ], 10, 2 );
        add_filter( 'manage_model_posts_columns', [ __CLASS__, 'add_manual_index_column' ] );
        add_action( 'manage_model_posts_custom_column', [ __CLASS__, 'render_manual_index_column' ], 10, 2 );
        add_action( 'restrict_manage_posts', [ __CLASS__, 'render_manual_index_filter' ], 10, 2 );
        add_action( 'pre_get_posts', [ __CLASS__, 'filter_models_by_manual_index_approval' ] );
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
            // published model has explicit manual index approval.
            if ( $ready === '0' ) {
                if ( self::has_manual_model_index_approval( (int) $post_id ) ) {
                    self::log_manual_model_index_approval( (int) $post_id, 'standalone_noindex' );
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
                // published model has explicit manual index approval.
                if ( $ready === '0' ) {
                    if ( self::has_manual_model_index_approval( (int) $post_id ) ) {
                        self::log_manual_model_index_approval( (int) $post_id, 'rank_math_robots' );
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


    /** Register the model edit-screen manual index approval metabox. */
    public static function register_manual_index_metabox(): void {
        add_meta_box(
            'tmwseo_manual_index_approval',
            esc_html__( 'Manual Index Approved', 'tmwseo' ),
            [ __CLASS__, 'render_manual_index_metabox' ],
            'model',
            'side',
            'default'
        );
    }

    /** Render the admin-only model edit-screen approval control. */
    public static function render_manual_index_metabox( \WP_Post $post ): void {
        wp_nonce_field( 'tmwseo_manual_index_approval_' . $post->ID, 'tmwseo_manual_index_approval_nonce' );

        $approved = (string) get_post_meta( $post->ID, self::META_MANUAL_INDEX_APPROVED, true ) === '1';

        echo '<p><label>';
        echo '<input type="checkbox" name="tmwseo_manual_index_approved" value="1" ' . checked( $approved, true, false ) . '> ';
        echo esc_html__( 'Manual Index Approved', 'tmwseo' );
        echo '</label></p>';
        echo '<p class="description">' . esc_html__( 'Allows this published model to bypass the TMW readiness noindex gate after manual review. This does not affect videos, categories, tags, archives, or other models.', 'tmwseo' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Readiness diagnostics remain active; this does not edit the TMW ready-to-index status or gate log.', 'tmwseo' ) . '</p>';
    }

    /** Save the model edit-screen manual index approval control securely. */
    public static function save_manual_index_metabox( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( $post->post_type !== 'model' ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $nonce = isset( $_POST['tmwseo_manual_index_approval_nonce'] )
            ? (string) wp_unslash( $_POST['tmwseo_manual_index_approval_nonce'] )
            : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'tmwseo_manual_index_approval_' . $post_id ) ) {
            return;
        }

        $approved = isset( $_POST['tmwseo_manual_index_approved'] )
            && (string) wp_unslash( $_POST['tmwseo_manual_index_approved'] ) === '1';

        if ( $approved ) {
            update_post_meta( $post_id, self::META_MANUAL_INDEX_APPROVED, '1' );
            error_log( sprintf(
                '[TMW-SEO-AUDIT] [TMW-INDEX-GATE] manual model index approval enabled post_id=%d slug=%s',
                $post_id,
                $post->post_name
            ) );
            return;
        }

        delete_post_meta( $post_id, self::META_MANUAL_INDEX_APPROVED );
        error_log( sprintf(
            '[TMW-SEO-AUDIT] [TMW-INDEX-GATE] manual model index approval disabled post_id=%d slug=%s',
            $post_id,
            $post->post_name
        ) );
    }

    /** Add the manual index approval column to the Models list table. */
    public static function add_manual_index_column( array $columns ): array {
        $columns['tmwseo_manual_index_approved'] = __( 'Manual Index Approved', 'tmwseo' );
        return $columns;
    }

    /** Render the manual index approval column value on the Models list table. */
    public static function render_manual_index_column( string $column, int $post_id ): void {
        if ( $column !== 'tmwseo_manual_index_approved' ) {
            return;
        }

        echo self::has_manual_model_index_approval( $post_id )
            ? esc_html__( 'Yes', 'tmwseo' )
            : esc_html__( 'No', 'tmwseo' );
    }

    /** Render a Models list filter for manual index approval state. */
    public static function render_manual_index_filter( string $post_type, string $which = 'top' ): void {
        unset( $which );

        if ( $post_type !== 'model' ) {
            return;
        }

        $selected = isset( $_GET['tmwseo_manual_index_approved_filter'] )
            ? sanitize_key( (string) wp_unslash( $_GET['tmwseo_manual_index_approved_filter'] ) )
            : '';

        echo '<label class="screen-reader-text" for="tmwseo_manual_index_approved_filter">' . esc_html__( 'Manual Index Approved', 'tmwseo' ) . '</label>';
        echo '<select name="tmwseo_manual_index_approved_filter" id="tmwseo_manual_index_approved_filter">';
        echo '<option value="">' . esc_html__( 'Manual Index Approved: All', 'tmwseo' ) . '</option>';
        echo '<option value="approved" ' . selected( $selected, 'approved', false ) . '>' . esc_html__( 'Approved', 'tmwseo' ) . '</option>';
        echo '<option value="not_approved" ' . selected( $selected, 'not_approved', false ) . '>' . esc_html__( 'Not approved', 'tmwseo' ) . '</option>';
        echo '</select>';
    }

    /** Apply the Models list manual index approval filter. */
    public static function filter_models_by_manual_index_approval( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( $post_type !== 'model' ) {
            return;
        }

        $filter = isset( $_GET['tmwseo_manual_index_approved_filter'] )
            ? sanitize_key( (string) wp_unslash( $_GET['tmwseo_manual_index_approved_filter'] ) )
            : '';

        if ( ! in_array( $filter, [ 'approved', 'not_approved' ], true ) ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        if ( $filter === 'approved' ) {
            $meta_query[] = [
                'key'   => self::META_MANUAL_INDEX_APPROVED,
                'value' => '1',
            ];
        } else {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => self::META_MANUAL_INDEX_APPROVED,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => self::META_MANUAL_INDEX_APPROVED,
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Does this post have reusable manual model index approval?
     *
     * This is intentionally narrow. It can only bypass this gate's noindex output
     * for published model singulars with explicit `_tmwseo_manual_index_approved`
     * meta. Videos/posts, categories, category archives, tags, archives,
     * random/filter URLs, and non-published models are unaffected.
     */
    public static function has_manual_model_index_approval( int $post_id ): bool {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        if ( $post->post_type !== 'model' || $post->post_status !== 'publish' ) {
            return false;
        }

        return (string) get_post_meta( $post_id, self::META_MANUAL_INDEX_APPROVED, true ) === '1';
    }

    /** Log when manual model index approval suppresses this gate's noindex. */
    private static function log_manual_model_index_approval( int $post_id, string $source ): void {
        unset( $source );

        $post = get_post( $post_id );
        $slug = $post instanceof \WP_Post ? $post->post_name : '';

        error_log( sprintf(
            '[TMW-INDEX-GATE] [TMW-NOINDEX-BLOCKER] manual model index approval suppressed noindex post_id=%d slug=%s',
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
