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
 * PR #708: IndexReadinessGate now respects explicit Rank Math per-post
 * robots index setting on published model pages. When Rank Math explicitly
 * contains 'index' (and not 'noindex'), TMW skips its own noindex injection
 * regardless of _tmwseo_ready_to_index. All other page types, draft/pending
 * models, videos, tags, and archives remain fully protected by the gate.
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

        // v5.9.13 — audit requirement "prove which write wins last": every
        // rank_math_robots meta write on an owned post type is logged with
        // the request context, so a later overwrite (e.g. a Gutenberg
        // Update replaying stale editor state after a server-side
        // generation) is detected and attributed instead of silently
        // re-noindexing a ready page. Log-only; never mutates.
        add_action( 'updated_post_meta', [ __CLASS__, 'log_robots_meta_write' ], 10, 4 );
        add_action( 'added_post_meta', [ __CLASS__, 'log_robots_meta_write' ], 10, 4 );
        add_action( 'deleted_post_meta', [ __CLASS__, 'log_robots_meta_delete' ], 10, 3 );
    }

    /**
     * @param int|int[] $meta_id
     * @param mixed     $meta_value
     */
    public static function log_robots_meta_write( $meta_id, int $post_id, string $meta_key, $meta_value ): void {
        if ( $meta_key !== 'rank_math_robots' ) {
            return;
        }
        if ( ! in_array( (string) get_post_type( $post_id ), [ 'model', 'post', 'tmw_category_page' ], true ) ) {
            return;
        }
        $value_arr  = is_array( $meta_value ) ? array_values( array_map( 'strval', $meta_value ) ) : [ (string) $meta_value ];
        $is_noindex = in_array( 'noindex', $value_arr, true );
        \TMWSEO\Engine\Logs::info( 'content', '[TMW-NOINDEX-SOURCE] rank_math_robots meta write observed', [
            'post_id'    => $post_id,
            'value'      => $value_arr,
            'noindex'    => $is_noindex,
            'doing_ajax' => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
            'doing_rest' => defined( 'REST_REQUEST' ) && REST_REQUEST,
            'doing_cron' => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
            'request'    => isset( $_SERVER['REQUEST_URI'] ) ? substr( (string) $_SERVER['REQUEST_URI'], 0, 120 ) : '',
        ] );
    }

    /** @param int|int[] $meta_ids */
    public static function log_robots_meta_delete( $meta_ids, int $post_id, string $meta_key ): void {
        if ( $meta_key !== 'rank_math_robots' ) {
            return;
        }
        if ( ! in_array( (string) get_post_type( $post_id ), [ 'model', 'post', 'tmw_category_page' ], true ) ) {
            return;
        }
        \TMWSEO\Engine\Logs::info( 'content', '[TMW-NOINDEX-SOURCE] rank_math_robots meta DELETED (theme bridge falls back to noindex on empty meta)', [
            'post_id' => $post_id,
            'request' => isset( $_SERVER['REQUEST_URI'] ) ? substr( (string) $_SERVER['REQUEST_URI'], 0, 120 ) : '',
        ] );
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

        // v5.9.15 — Gate: category active keyword coverage (fail-closed).
        // The July 2026 audit proved a category could reach readiness while
        // keywords stored in the Rank Math focus-keyword CSV never appeared
        // in the content ("active but unplaced" chips). The CSV is now
        // written as the placeable/covered active set, and this gate is the
        // end-to-end enforcement: EVERY stored non-primary keyword must be
        // visible in the persisted content AND in an H2-H6 subheading, or be
        // provably covered by the primary phrase (Rank Math substring
        // semantics: contiguous token subsequence of a primary that itself
        // appears in content and a subheading). Any uncovered active keyword
        // blocks readiness with a named reason, so the page keeps noindex
        // instead of going live with a broken keyword contract.
        if ( $post_type === 'tmw_category_page' ) {
            $csv      = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
            $coverage = self::category_active_chip_coverage( (string) $post->post_content, $csv );
            $details['active_chip_coverage'] = $coverage;
            foreach ( (array) $coverage['failures'] as $failure ) {
                $reasons[] = 'active_chip_unused:' . (string) ( $failure['keyword'] ?? '' ) . ':' . (string) ( $failure['reason'] ?? '' );
            }
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
     * v5.9.15 — pure coverage check for the category active keyword contract.
     *
     * No WordPress calls: takes the persisted HTML and the stored Rank Math
     * focus-keyword CSV and reports, per non-primary keyword, whether the
     * exact boundary-guarded phrase appears in (a) the visible content and
     * (b) at least one H2-H6 subheading — or whether the keyword is a
     * contiguous token subsequence of the primary phrase, in which case the
     * primary's own presence in content + subheading covers it under the
     * shipped Rank Math analyzer's substring semantics.
     *
     * @return array{passed:bool,checked:int,failures:array<int,array{keyword:string,reason:string}>,rows:array<string,array<string,mixed>>}
     */
    public static function category_active_chip_coverage( string $html, string $focus_csv ): array {
        $keywords = array_values( array_filter( array_map( 'trim', explode( ',', $focus_csv ) ), 'strlen' ) );
        $result   = [ 'passed' => true, 'checked' => 0, 'failures' => [], 'rows' => [] ];
        if ( count( $keywords ) < 1 || trim( $html ) === '' ) {
            return $result;
        }
        $primary = (string) $keywords[0];

        $visible = (string) preg_replace( [ '/<script\b[^>]*>.*?<\/script>/isu', '/<style\b[^>]*>.*?<\/style>/isu', '/<!--.*?-->/su' ], ' ', $html );
        $visible = (string) preg_replace( '/<[^>]+>/u', ' ', $visible ); // tags become spaces so adjacent block boundaries never glue words
        $visible = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $visible, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        $sub_txt = '';
        if ( preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/isu', $html, $shm ) ) {
            $sub_txt = (string) preg_replace( '/<[^>]+>/u', ' ', implode( "\n", $shm[2] ) );
            $sub_txt = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $sub_txt, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        }
        $pattern = static function ( string $kw ): string {
            return '/(?<![\p{L}\p{N}])' . preg_quote( $kw, '/' ) . '(?![\p{L}\p{N}])/iu';
        };
        $tokens = static function ( string $s ): array {
            $s = (string) preg_replace( '/[^a-z0-9\s]+/u', ' ', function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s ) );
            return array_values( array_filter( preg_split( '/\s+/u', trim( $s ) ) ?: [], 'strlen' ) );
        };
        $contained_in_primary = static function ( string $kw ) use ( $tokens, $primary ): bool {
            $n = $tokens( $kw );
            $h = $tokens( $primary );
            if ( empty( $n ) || count( $n ) >= count( $h ) ) {
                return false;
            }
            for ( $i = 0; $i <= count( $h ) - count( $n ); $i++ ) {
                if ( array_slice( $h, $i, count( $n ) ) === $n ) {
                    return true;
                }
            }
            return false;
        };

        $primary_in_content = (bool) preg_match( $pattern( $primary ), $visible );
        $primary_in_sub     = (bool) preg_match( $pattern( $primary ), $sub_txt );

        foreach ( $keywords as $index => $kw ) {
            if ( $index === 0 ) {
                continue; // The primary's own checks belong to Rank Math scoring, not this contract gate.
            }
            $result['checked']++;
            $in_content = (bool) preg_match( $pattern( $kw ), $visible );
            $in_sub     = (bool) preg_match( $pattern( $kw ), $sub_txt );
            $covered    = ! $in_content && $contained_in_primary( $kw ) && $primary_in_content && $primary_in_sub;
            $reason     = '';
            if ( ! $in_content && ! $covered ) {
                $reason = 'absent_from_visible_content';
            } elseif ( ! $in_sub && ! $covered ) {
                $reason = 'absent_from_h2_h6_subheadings';
            }
            $result['rows'][ $kw ] = [
                'in_content'         => $in_content,
                'in_subheading'      => $in_sub,
                'covered_by_primary' => $covered,
                'reason'             => $reason,
            ];
            if ( $reason !== '' ) {
                $result['passed']     = false;
                $result['failures'][] = [ 'keyword' => $kw, 'reason' => $reason ];
            }
        }
        return $result;
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
     *
     * PR #708: For singular published model posts only, if Rank Math has an
     * explicit per-post 'index' setting (without 'noindex'), TMW skips its
     * own noindex echo and defers to Rank Math's decision.
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

            // [TMW-INDEX-GATE] If Rank Math explicitly approved this published model
            // for indexing, do not inject TMW's own noindex tag.
            if ( self::has_explicit_rankmath_index( $post_id ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( sprintf(
                        '[TMW-INDEX-GATE] [TMW-NOINDEX-BLOCKER] Rank Math explicit index preserved post_id=%d slug=%s',
                        $post_id,
                        (string) get_post_field( 'post_name', $post_id )
                    ) );
                }
                return;
            }

            $ready = get_post_meta( $post_id, self::META_READY, true );
            // If explicitly evaluated and not ready, inject noindex.
            if ( $ready === '0' ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( sprintf(
                        '[TMW-NOINDEX-SOURCE] class=IndexReadinessGate function=maybe_inject_noindex post_id=%d slug=%s source=direct_echo',
                        $post_id,
                        (string) get_post_field( 'post_name', $post_id )
                    ) );
                }
                echo '<meta name="robots" content="noindex, follow">' . "\n";
            }
        }
    }

    /**
     * Filter Rank Math robots meta for tag archives and weak posts.
     *
     * PR #708: For singular published model posts only, if Rank Math has an
     * explicit per-post 'index' setting (without 'noindex'), TMW returns the
     * original robots array unchanged and does not force noindex.
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
                // [TMW-INDEX-GATE] If Rank Math explicitly approved this published model
                // for indexing, do not override the robots array to noindex.
                if ( self::has_explicit_rankmath_index( $post_id ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log( sprintf(
                            '[TMW-INDEX-GATE] [TMW-NOINDEX-BLOCKER] Rank Math explicit index preserved post_id=%d slug=%s',
                            $post_id,
                            (string) get_post_field( 'post_name', $post_id )
                        ) );
                    }
                    return $robots;
                }

                $ready = get_post_meta( $post_id, self::META_READY, true );
                // If explicitly evaluated and not ready, noindex.
                if ( $ready === '0' ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log( sprintf(
                            '[TMW-NOINDEX-SOURCE] class=IndexReadinessGate function=filter_rank_math_robots post_id=%d slug=%s source=rank_math_filter',
                            $post_id,
                            (string) get_post_field( 'post_name', $post_id )
                        ) );
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

    /**
     * Returns true only when Rank Math has an explicit Index (not noindex) set
     * for this post AND the post is a published model.
     *
     * This is the TMW noindex override guard (PR #708). When an operator sets
     * Rank Math Advanced Robots to Index on a model page, TMW must not
     * independently force noindex via its readiness gate, regardless of whether
     * _tmwseo_ready_to_index has been evaluated.
     *
     * Safety constraints — ALL must be true to return true:
     *  - Post must exist.
     *  - Post type must be 'model'. Videos ('post'), categories, tags, archives
     *    are never affected.
     *  - Post status must be 'publish'. Drafts, pending, private models remain
     *    fully protected by the gate.
     *  - rank_math_robots must explicitly contain 'index'.
     *  - rank_math_robots must NOT contain 'noindex'. If both are present,
     *    the conservative path is taken and the gate remains active.
     *  - If rank_math_robots is empty or missing (no per-post override set),
     *    returns false — the gate remains active.
     *
     * Does NOT write to any meta. Read-only. No side effects.
     *
     * @param int $post_id
     * @return bool
     */
    private static function has_explicit_rankmath_index( int $post_id ): bool {
        // Must be a published model post.
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }
        if ( $post->post_type !== 'model' || $post->post_status !== 'publish' ) {
            return false;
        }

        // Read Rank Math per-post robots via the canonical TMW reader when available.
        if ( class_exists( RankMathReader::class ) ) {
            $rm_robots = RankMathReader::get_robots( $post_id );
        } else {
            // Fallback: read raw meta directly. Only accept array values —
            // do not attempt to parse strings to avoid false positives.
            $raw = get_post_meta( $post_id, 'rank_math_robots', true );
            if ( ! is_array( $raw ) ) {
                return false;
            }
            $rm_robots = array_values( array_filter(
                array_map( 'strtolower', array_map( 'trim', $raw ) ),
                'strlen'
            ) );
        }

        // No per-post robots setting: Rank Math has not explicitly approved.
        // Gate remains active.
        if ( empty( $rm_robots ) ) {
            return false;
        }

        $has_index   = in_array( 'index',   $rm_robots, true );
        $has_noindex = in_array( 'noindex', $rm_robots, true );

        // Only bypass the gate when Rank Math explicitly contains 'index'
        // and does NOT contain 'noindex'. Both present = conservative = gate active.
        return $has_index && ! $has_noindex;
    }
}
