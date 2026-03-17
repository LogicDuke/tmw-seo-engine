<?php
/**
 * TMW SEO Engine — Ownership Enforcer
 *
 * Preventive keyword-to-page ownership enforcement.
 * Checks BEFORE assignment whether a keyword belongs to a page type,
 * and blocks conflicting assignments with an explanation.
 *
 * Priority order: Model (10) > Category (7) > Tag (4) > Video (5 for scene-specific).
 * Model always wins for name-based queries.
 * Category wins for category head terms.
 * Tag only wins if no higher-priority page claims the keyword.
 * Video owns scene-specific / watch-intent only.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.0.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OwnershipEnforcer {

    /**
     * Page-type priority for ownership disputes.
     * Higher number = higher priority.
     */
    private const PAGE_TYPE_PRIORITY = [
        'model'             => 10,
        'tmw_category_page' => 7,
        'post'              => 5, // video posts
        'page'              => 3,
    ];

    /** Post meta keys for keyword assignment. */
    private const META_PRIMARY   = '_tmwseo_keyword';
    private const META_SECONDARY = '_tmwseo_secondary_keywords';

    /**
     * Check whether a keyword can be assigned to a specific page.
     *
     * Returns an allow/block result with reasons.
     *
     * @param string $keyword   The keyword to assign.
     * @param int    $post_id   The target post.
     * @param string $role      'primary' or 'secondary'.
     * @return array{allowed:bool, reason:string, conflicts:array, suggested_owner:string}
     */
    public static function check_assignment( string $keyword, int $post_id, string $role = 'primary' ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return self::result( false, 'post_not_found', [], '' );
        }

        $keyword_lower = mb_strtolower( trim( $keyword ), 'UTF-8' );
        if ( $keyword_lower === '' ) {
            return self::result( false, 'empty_keyword', [], '' );
        }

        $target_type     = $post->post_type;
        $target_priority = self::PAGE_TYPE_PRIORITY[ $target_type ] ?? 1;

        // ── Rule 1: Model name ownership ──────────────────────────
        // If the keyword IS a model name or starts with a model name,
        // only the model page should own it as primary.
        $model_match = self::find_model_owner( $keyword_lower );
        if ( $model_match ) {
            if ( $target_type !== 'model' || (int) $post_id !== (int) $model_match['post_id'] ) {
                // Allow as secondary on video pages (scene-specific variants)
                if ( $target_type === 'post' && $role === 'secondary' && self::is_scene_specific( $keyword_lower, $model_match['name'] ) ) {
                    // Permitted: video page can hold model-name scene variants as secondary
                } else {
                    return self::result(
                        false,
                        'model_page_owns_keyword',
                        [ $model_match ],
                        'model:' . $model_match['post_id']
                    );
                }
            }
        }

        // ── Rule 2: Category head-term ownership ──────────────────
        $category_match = self::find_category_owner( $keyword_lower );
        if ( $category_match && $target_type !== 'tmw_category_page' ) {
            if ( $target_priority < ( self::PAGE_TYPE_PRIORITY['tmw_category_page'] ?? 7 ) ) {
                return self::result(
                    false,
                    'category_page_owns_keyword',
                    [ $category_match ],
                    'category:' . $category_match['term_id']
                );
            }
        }

        // ── Rule 3: Check existing primary assignments ────────────
        $existing = self::find_existing_primary_owner( $keyword_lower, $post_id );
        if ( ! empty( $existing ) ) {
            foreach ( $existing as $conflict ) {
                $conflict_priority = self::PAGE_TYPE_PRIORITY[ $conflict['post_type'] ] ?? 1;
                if ( $conflict_priority > $target_priority ) {
                    return self::result(
                        false,
                        'higher_priority_page_owns_keyword',
                        [ $conflict ],
                        $conflict['post_type'] . ':' . $conflict['post_id']
                    );
                }
                if ( $conflict_priority === $target_priority && $role === 'primary' ) {
                    return self::result(
                        false,
                        'same_type_primary_conflict',
                        [ $conflict ],
                        $conflict['post_type'] . ':' . $conflict['post_id']
                    );
                }
            }
        }

        // ── Rule 4: Tag page restrictions ─────────────────────────
        if ( $target_type === 'page' || self::is_tag_archive_post( $post_id ) ) {
            // Tag pages can only own narrow descriptors, not broad terms
            if ( mb_strlen( $keyword_lower ) < 10 && substr_count( $keyword_lower, ' ' ) < 2 ) {
                return self::result(
                    false,
                    'tag_page_keyword_too_broad',
                    [],
                    ''
                );
            }
        }

        return self::result( true, 'assignment_allowed', [], '' );
    }

    /**
     * Enforce assignment: check + log + optionally block.
     *
     * @return array Same as check_assignment, with 'enforced' key added.
     */
    public static function enforce_assignment( string $keyword, int $post_id, string $role = 'primary' ): array {
        $check = self::check_assignment( $keyword, $post_id, $role );

        Logs::info( 'ownership', '[TMW-OWN] Assignment enforcement check', [
            'keyword'   => $keyword,
            'post_id'   => $post_id,
            'role'      => $role,
            'allowed'   => $check['allowed'],
            'reason'    => $check['reason'],
            'conflicts' => count( $check['conflicts'] ),
        ] );

        if ( ! $check['allowed'] ) {
            // Store the block reason on the post for admin visibility
            $blocks = (array) json_decode(
                (string) get_post_meta( $post_id, '_tmwseo_ownership_blocks', true ),
                true
            );
            $blocks[] = [
                'keyword'   => $keyword,
                'reason'    => $check['reason'],
                'suggested' => $check['suggested_owner'],
                'checked'   => current_time( 'mysql' ),
            ];
            // Keep only last 10 blocks
            $blocks = array_slice( $blocks, -10 );
            update_post_meta( $post_id, '_tmwseo_ownership_blocks', wp_json_encode( $blocks ) );
        }

        $check['enforced'] = true;
        return $check;
    }

    /**
     * Suggest the correct page type for a keyword.
     *
     * @return array{page_type:string, reason:string, entity_id:int}
     */
    public static function suggest_owner( string $keyword ): array {
        $kw = mb_strtolower( trim( $keyword ), 'UTF-8' );

        // Model name match
        $model = self::find_model_owner( $kw );
        if ( $model ) {
            return [
                'page_type' => 'model',
                'reason'    => 'keyword_contains_model_name',
                'entity_id' => (int) $model['post_id'],
            ];
        }

        // Category match
        $cat = self::find_category_owner( $kw );
        if ( $cat ) {
            return [
                'page_type' => 'tmw_category_page',
                'reason'    => 'keyword_matches_category',
                'entity_id' => (int) $cat['term_id'],
            ];
        }

        // Scene-specific / video intent
        if ( self::has_video_intent( $kw ) ) {
            return [
                'page_type' => 'post',
                'reason'    => 'keyword_has_video_intent',
                'entity_id' => 0,
            ];
        }

        // Default: generic / unassigned
        return [
            'page_type' => '',
            'reason'    => 'no_specific_owner_detected',
            'entity_id' => 0,
        ];
    }

    // ── Internal helpers ──────────────────────────────────────

    private static function result( bool $allowed, string $reason, array $conflicts, string $suggested_owner ): array {
        return [
            'allowed'         => $allowed,
            'reason'          => $reason,
            'conflicts'       => $conflicts,
            'suggested_owner' => $suggested_owner,
        ];
    }

    /**
     * Check if a keyword matches a published model name.
     *
     * @return array|null  { post_id, name } or null.
     */
    private static function find_model_owner( string $keyword_lower ): ?array {
        static $model_names = null;

        if ( $model_names === null ) {
            $model_names = [];
            $ids = get_posts( [
                'post_type'      => 'model',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );

            if ( is_array( $ids ) ) {
                foreach ( $ids as $id ) {
                    $name = mb_strtolower( trim( (string) get_the_title( (int) $id ) ), 'UTF-8' );
                    if ( $name !== '' ) {
                        $model_names[] = [ 'post_id' => (int) $id, 'name' => $name ];
                    }
                }
            }
        }

        foreach ( $model_names as $model ) {
            // Exact match or keyword starts with model name
            if ( $keyword_lower === $model['name'] ) {
                return $model;
            }
            if ( strpos( $keyword_lower, $model['name'] . ' ' ) === 0 ) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Check if a keyword matches a known category.
     *
     * @return array|null { term_id, name } or null.
     */
    private static function find_category_owner( string $keyword_lower ): ?array {
        static $categories = null;

        if ( $categories === null ) {
            $categories = [];
            $terms = get_terms( [
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'fields'     => 'all',
            ] );

            if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $name = mb_strtolower( trim( $term->name ), 'UTF-8' );
                    if ( $name !== '' && $name !== 'uncategorized' ) {
                        $categories[] = [ 'term_id' => $term->term_id, 'name' => $name ];
                    }
                }
            }
        }

        foreach ( $categories as $cat ) {
            // Keyword IS the category name or starts with it + modifier
            if ( $keyword_lower === $cat['name'] ) {
                return $cat;
            }
            $cat_cam = $cat['name'] . ' cam';
            $cat_webcam = $cat['name'] . ' webcam';
            if ( strpos( $keyword_lower, $cat_cam ) === 0 || strpos( $keyword_lower, $cat_webcam ) === 0 ) {
                return $cat;
            }
        }

        return null;
    }

    /**
     * Find existing posts that have this keyword as their primary keyword.
     */
    private static function find_existing_primary_owner( string $keyword_lower, int $exclude_post_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID AS post_id, p.post_type, pm.meta_value AS keyword
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_status IN ('publish','draft','pending')
               AND p.ID != %d
               AND pm.meta_value != ''
             LIMIT 500",
            self::META_PRIMARY,
            $exclude_post_id
        ), ARRAY_A );

        $matches = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $existing_kw = mb_strtolower( trim( (string) $row['keyword'] ), 'UTF-8' );
                if ( $existing_kw === $keyword_lower ) {
                    $matches[] = [
                        'post_id'   => (int) $row['post_id'],
                        'post_type' => (string) $row['post_type'],
                        'keyword'   => (string) $row['keyword'],
                    ];
                }
            }
        }

        return $matches;
    }

    private static function is_scene_specific( string $keyword, string $model_name ): bool {
        $scene_markers = [ 'video', 'show', 'scene', 'clip', 'recording', 'performance' ];
        $suffix = trim( str_replace( $model_name, '', $keyword ) );
        foreach ( $scene_markers as $marker ) {
            if ( strpos( $suffix, $marker ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function has_video_intent( string $keyword ): bool {
        $video_markers = [ 'watch', 'video', 'clip', 'scene', 'recording', 'show replay' ];
        foreach ( $video_markers as $marker ) {
            if ( strpos( $keyword, $marker ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function is_tag_archive_post( int $post_id ): bool {
        // Tag archives are not custom post types in WP, but if the system
        // creates dedicated tag landing pages, check for that pattern.
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }
        return (string) get_post_meta( $post_id, '_tmwseo_page_type', true ) === 'tag_landing';
    }
}
