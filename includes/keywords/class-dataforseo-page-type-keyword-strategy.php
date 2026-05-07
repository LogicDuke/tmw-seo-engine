<?php
/**
 * TMW SEO Engine — DataForSEO Page-Type Keyword Strategy (foundation pass)
 *
 * Builds page-type-aware seed groups and a recommended DataForSEO endpoint
 * plan for each of the five strategic page types:
 *
 *   - model        (model directory pages)
 *   - video        (video / scene posts)
 *   - category     (curated category landing pages)
 *   - tag          (tag landing pages)
 *   - opportunity  (gap / cluster keyword pages)
 *
 * SCOPE OF THIS PASS
 * ------------------
 * This class is intentionally a PREVIEW / DRY-RUN layer:
 *
 *   - It NEVER calls the DataForSEO API by itself.
 *   - It NEVER creates posts, drafts, candidates, or DB rows.
 *   - It NEVER changes admin handlers, routes, scoring, or Ranking Probability.
 *
 * It only:
 *
 *   1. Inspects an existing post and builds a normalized context array.
 *   2. Generates structured seed groups per page type, with hard rules that
 *      reject shapes which don't fit the page type (e.g. tag-only seeds for
 *      a model page).
 *   3. Returns a "preview plan" describing which DataForSEO endpoints WOULD
 *      be queried in a future paid scan, including warnings if context is
 *      weak.
 *   4. Provides a lightweight candidate-shape heuristic (longtail length,
 *      entity match, listing intent) — explicitly NOT a final ranking
 *      probability score.
 *
 * Future passes will:
 *
 *   - Add the run-ledger DB tables (tmwseo_scan_runs, tmwseo_scan_tasks,
 *     tmwseo_scan_items, tmwseo_candidate_versions, ...).
 *   - Wire the preview plan to a paid-scan executor with a pre-flight modal.
 *   - Persist fetched / filtered / stored deltas with run freshness labels.
 *
 * Those concerns are deliberately OUT OF SCOPE here.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.0-dfseo-foundation
 */

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DataForSEOPageTypeKeywordStrategy {

    public const PAGE_TYPE_MODEL       = 'model';
    public const PAGE_TYPE_VIDEO       = 'video';
    public const PAGE_TYPE_CATEGORY    = 'category';
    public const PAGE_TYPE_TAG         = 'tag';
    public const PAGE_TYPE_OPPORTUNITY = 'opportunity';

    public const PAGE_TYPES = [
        self::PAGE_TYPE_MODEL,
        self::PAGE_TYPE_VIDEO,
        self::PAGE_TYPE_CATEGORY,
        self::PAGE_TYPE_TAG,
        self::PAGE_TYPE_OPPORTUNITY,
    ];

    /**
     * Forbidden tokens for category / tag pages — these would route the query
     * back into model territory and break the listing intent.
     */
    private const LISTING_INTENT_TOKENS = [
        'models', 'cams', 'cam girls', 'webcam', 'live cam',
        'live show', 'profiles', 'videos', 'streams',
    ];

    /**
     * Generic adult tokens that are too broad for tag pages without a listing
     * modifier. Used by passes_page_type_rules() in the candidate-shape
     * heuristic (NOT used to filter API responses — that happens later).
     */
    private const HYPER_GENERIC_TAG_TOKENS = [
        'porn', 'sex', 'nude', 'naked', 'adult', 'xxx',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Context building
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inspect a post and return a normalized context dictionary.
     *
     * @return array{
     *   post_id:int,
     *   post_type:string,
     *   page_type:string,
     *   entity_name:string,
     *   slug:string,
     *   permalink:string,
     *   url_pattern_group:string,
     *   taxonomy_tags:string[],
     *   taxonomy_categories:string[],
     *   verified_platforms:string[],
     *   verified_handles:string[],
     *   source_platform:string,
     *   has_strong_entity:bool,
     *   warnings:string[]
     * }
     */
    public static function build_context_from_post( int $post_id ): array {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return self::empty_context();
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return self::empty_context();
        }

        $page_type = self::detect_page_type( $post );
        $entity_name = trim( (string) $post->post_title );

        $tags       = self::collect_taxonomy_terms( $post, 'tag' );
        $categories = self::collect_taxonomy_terms( $post, 'category' );
        $related_terms = self::PAGE_TYPE_MODEL === $page_type
            ? self::collect_related_model_content_terms( $post )
            : [ 'tags' => [], 'categories' => [], 'modifiers' => [] ];

        $platforms = self::collect_verified_platforms( $post_id );
        $handles   = self::collect_verified_handles( $post_id, $platforms );
        $source_platform = $platforms[0] ?? '';

        $url_pattern_group = self::detect_url_pattern_group( $post, $page_type );

        $context = [
            'post_id'             => $post_id,
            'post_type'           => (string) $post->post_type,
            'page_type'           => $page_type,
            'entity_name'         => $entity_name,
            'slug'                => (string) $post->post_name,
            'permalink'           => (string) get_permalink( $post ),
            'url_pattern_group'   => $url_pattern_group,
            'taxonomy_tags'       => $tags,
            'taxonomy_categories' => $categories,
            'related_content_tags' => (array) ( $related_terms['tags'] ?? [] ),
            'related_content_categories' => (array) ( $related_terms['categories'] ?? [] ),
            'modifier_terms'      => (array) ( $related_terms['modifiers'] ?? [] ),
            'verified_platforms'  => $platforms,
            'verified_handles'    => $handles,
            'source_platform'     => $source_platform,
            'has_strong_entity'   => false,
            'warnings'            => [],
        ];

        $context['has_strong_entity'] = self::evaluate_strong_entity( $context );
        $context['warnings'] = self::collect_warnings( $context );

        return $context;
    }

    /**
     * Detect the page type for a post using the same conventions used
     * elsewhere in the plugin (post_type 'model' / 'tmw_category_page',
     * meta '_tmwseo_page_type'='tag_landing', and 'post' as default video
     * carrier on this site).
     */
    private static function detect_page_type( \WP_Post $post ): string {
        if ( $post->post_type === 'model' ) {
            return self::PAGE_TYPE_MODEL;
        }
        if ( $post->post_type === 'tmw_category_page' ) {
            return self::PAGE_TYPE_CATEGORY;
        }
        $explicit = (string) get_post_meta( $post->ID, '_tmwseo_page_type', true );
        if ( $explicit === 'tag_landing' ) {
            return self::PAGE_TYPE_TAG;
        }
        if ( $explicit === 'opportunity' ) {
            return self::PAGE_TYPE_OPPORTUNITY;
        }
        if ( $post->post_type === 'post' ) {
            return self::PAGE_TYPE_VIDEO;
        }
        return '';
    }

    /**
     * Collect taxonomy term slugs on the post, separated by "tag-like" vs
     * "category-like" taxonomies. We use the term taxonomy hierarchy as a
     * cheap heuristic (hierarchical → category, flat → tag).
     *
     * @return string[]
     */
    private static function collect_taxonomy_terms( \WP_Post $post, string $kind ): array {
        $taxes = (array) get_object_taxonomies( $post->post_type );
        $out   = [];
        foreach ( $taxes as $tax_name ) {
            $tax_obj = get_taxonomy( $tax_name );
            if ( ! $tax_obj ) { continue; }
            $is_hierarchical = ! empty( $tax_obj->hierarchical );
            if ( $kind === 'category' && ! $is_hierarchical ) { continue; }
            if ( $kind === 'tag' && $is_hierarchical ) { continue; }

            $terms = get_the_terms( $post, $tax_name );
            if ( ! is_array( $terms ) ) { continue; }
            foreach ( $terms as $term ) {
                if ( ! ( $term instanceof \WP_Term ) ) { continue; }
                $slug = sanitize_key( (string) $term->slug );
                if ( $slug === '' || $slug === 'uncategorized' ) { continue; }
                $out[] = $slug;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Verified platform slugs for a model post. Reads from PlatformProfiles
     * if available; falls back to the primary-platform meta.
     *
     * @return string[]
     */
    private static function collect_verified_platforms( int $post_id ): array {
        $slugs = [];
        if ( class_exists( '\TMWSEO\Engine\Platform\PlatformProfiles' ) ) {
            try {
                $rows = \TMWSEO\Engine\Platform\PlatformProfiles::get_links( $post_id );
                if ( is_array( $rows ) ) {
                    foreach ( $rows as $row ) {
                        if ( ! is_array( $row ) ) { continue; }
                        $p = isset( $row['platform'] ) ? sanitize_key( (string) $row['platform'] ) : '';
                        if ( $p !== '' ) {
                            $slugs[] = $p;
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // Swallow — read-only context build must never fatal.
            }
        }
        $primary = sanitize_key( (string) get_post_meta( $post_id, '_tmwseo_platform_primary', true ) );
        if ( $primary !== '' ) {
            $slugs[] = $primary;
        }
        return array_values( array_unique( array_filter( $slugs, 'strlen' ) ) );
    }

    /**
     * Verified handles per platform, where available via the standard meta key.
     *
     * @param string[] $platforms
     * @return string[] Flat list of "platform:handle" strings.
     */
    private static function collect_verified_handles( int $post_id, array $platforms ): array {
        $out = [];
        foreach ( $platforms as $platform ) {
            $handle = trim( (string) get_post_meta( $post_id, '_tmwseo_platform_username_' . $platform, true ) );
            if ( $handle !== '' ) {
                $out[] = $platform . ':' . $handle;
            }
        }
        return $out;
    }

    /**
     * Coarse URL pattern grouping (e.g. "/model/", "/video/", "/category/",
     * "/tag/"). Used in opportunity-style page_intersection seed building.
     */
    private static function detect_url_pattern_group( \WP_Post $post, string $page_type ): string {
        $permalink = (string) get_permalink( $post );
        if ( $permalink !== '' ) {
            $path = (string) wp_parse_url( $permalink, PHP_URL_PATH );
            if ( $path !== '' ) {
                $segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
                if ( ! empty( $segments ) ) {
                    return '/' . $segments[0] . '/';
                }
            }
        }
        switch ( $page_type ) {
            case self::PAGE_TYPE_MODEL:    return '/model/';
            case self::PAGE_TYPE_VIDEO:    return '/video/';
            case self::PAGE_TYPE_CATEGORY: return '/category/';
            case self::PAGE_TYPE_TAG:      return '/tag/';
            default:                       return '/';
        }
    }

    private static function evaluate_strong_entity( array $context ): bool {
        $page_type = (string) ( $context['page_type'] ?? '' );
        $name      = (string) ( $context['entity_name'] ?? '' );

        if ( $name === '' ) { return false; }

        if ( $page_type === self::PAGE_TYPE_MODEL ) {
            return ! empty( $context['verified_platforms'] ) || ! empty( $context['verified_handles'] );
        }
        if ( $page_type === self::PAGE_TYPE_VIDEO ) {
            return ! empty( $context['taxonomy_tags'] ) || ! empty( $context['verified_platforms'] );
        }
        if ( in_array( $page_type, [ self::PAGE_TYPE_CATEGORY, self::PAGE_TYPE_TAG ], true ) ) {
            return true; // The page name itself IS the entity for these types.
        }
        return false;
    }

    private static function collect_warnings( array $context ): array {
        $warnings = [];
        $page_type = (string) ( $context['page_type'] ?? '' );

        if ( $page_type === '' ) {
            $warnings[] = 'unknown_page_type';
        }
        if ( ( $context['entity_name'] ?? '' ) === '' ) {
            $warnings[] = 'missing_entity_name';
        }
        if ( $page_type === self::PAGE_TYPE_MODEL ) {
            if ( empty( $context['verified_platforms'] ) ) {
                $warnings[] = 'model_has_no_verified_platform';
            }
            if ( empty( $context['verified_handles'] ) ) {
                $warnings[] = 'model_has_no_verified_handle';
            }
        }
        if ( $page_type === self::PAGE_TYPE_VIDEO && empty( $context['taxonomy_tags'] ) ) {
            $warnings[] = 'video_has_no_descriptor_tags';
        }
        if ( $page_type === self::PAGE_TYPE_TAG && ( $context['entity_name'] ?? '' ) === '' ) {
            $warnings[] = 'tag_has_no_label';
        }
        return $warnings;
    }

    private static function empty_context(): array {
        return [
            'post_id'             => 0,
            'post_type'           => '',
            'page_type'           => '',
            'entity_name'         => '',
            'slug'                => '',
            'permalink'           => '',
            'url_pattern_group'   => '/',
            'taxonomy_tags'       => [],
            'taxonomy_categories' => [],
            'related_content_tags' => [],
            'related_content_categories' => [],
            'modifier_terms'      => [],
            'verified_platforms'  => [],
            'verified_handles'    => [],
            'source_platform'     => '',
            'has_strong_entity'   => false,
            'warnings'            => [ 'invalid_post' ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seed builders (per page type)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<int,array{group:string,seed:string,intent:string}>
     */
    public static function build_model_seeds( array $context ): array {
        $name       = self::tidy_phrase( (string) ( $context['entity_name'] ?? '' ) );
        $platforms  = (array) ( $context['verified_platforms'] ?? [] );
        $handles    = (array) ( $context['verified_handles'] ?? [] );
        $tags       = self::tidy_terms( (array) ( $context['taxonomy_tags'] ?? [] ) );
        $categories = self::tidy_terms( (array) ( $context['taxonomy_categories'] ?? [] ) );
        $related_tags = self::tidy_terms( (array) ( $context['related_content_tags'] ?? [] ) );
        $related_categories = self::tidy_terms( (array) ( $context['related_content_categories'] ?? [] ) );
        $modifier_terms = self::tidy_terms( (array) ( $context['modifier_terms'] ?? [] ) );

        if ( $name === '' ) { return []; }

        $entity_norm = self::normalize_term_for_compare( $name );
        $collected_modifiers = self::merge_modifier_terms(
            $entity_norm,
            $modifier_terms,
            $related_tags,
            $related_categories,
            $tags,
            $categories
        );

        $seeds = [];
        $seeds[] = [ 'group' => 'name_only', 'seed' => $name, 'intent' => 'navigational' ];

        foreach ( $platforms as $platform ) {
            $platform_label = self::tidy_phrase( str_replace( '_', ' ', (string) $platform ) );
            if ( $platform_label === '' ) { continue; }
            $seeds[] = [ 'group' => 'name_platform', 'seed' => "{$name} {$platform_label}", 'intent' => 'navigational' ];
        }
        foreach ( array_slice( $collected_modifiers, 0, 6 ) as $modifier ) {
            $seeds[] = [ 'group' => 'name_modifier', 'seed' => "{$name} {$modifier}", 'intent' => 'commercial' ];
        }

        foreach ( $platforms as $platform ) {
            $platform_label = self::tidy_phrase( str_replace( '_', ' ', (string) $platform ) );
            if ( $platform_label === '' ) { continue; }
            $combo = self::best_platform_modifier_combo( $platform_label, $collected_modifiers );
            if ( $combo !== '' ) {
                $seeds[] = [ 'group' => 'name_platform_modifier', 'seed' => "{$name} {$platform_label} {$combo}", 'intent' => 'transactional' ];
            }
        }

        $seeds[] = [ 'group' => 'live_cam', 'seed' => "{$name} live cam", 'intent' => 'transactional' ];
        $seeds[] = [ 'group' => 'watch_live', 'seed' => "watch {$name} live", 'intent' => 'transactional' ];
        $seeds[] = [ 'group' => 'official_links', 'seed' => "{$name} official links", 'intent' => 'navigational' ];
        $seeds[] = [ 'group' => 'live_cam_schedule', 'seed' => "{$name} live cam schedule", 'intent' => 'informational' ];

        foreach ( $handles as $handle_pair ) {
            // Stored as "platform:handle".
            if ( ! is_string( $handle_pair ) || strpos( $handle_pair, ':' ) === false ) { continue; }
            [ $platform_slug, $handle ] = array_pad( explode( ':', $handle_pair, 2 ), 2, '' );
            $platform_label = self::tidy_phrase( str_replace( '_', ' ', $platform_slug ) );
            $handle_label   = self::tidy_phrase( $handle );
            if ( $handle_label === '' || $platform_label === '' ) { continue; }
            $seeds[] = [
                'group'  => 'handle_platform',
                'seed'   => "{$handle_label} {$platform_label}",
                'intent' => 'navigational',
            ];
        }

        return self::dedupe_seeds( $seeds );
    }

    /**
     * @return array{tags:string[],categories:string[],modifiers:string[]}
     */
    private static function collect_related_model_content_terms( \WP_Post $model_post ): array {
        $related_posts = self::find_related_posts_for_model( $model_post );
        if ( empty( $related_posts ) ) {
            return [ 'tags' => [], 'categories' => [], 'modifiers' => [] ];
        }

        $tag_terms = [];
        $category_terms = [];
        foreach ( $related_posts as $related_post ) {
            $tag_terms = array_merge( $tag_terms, self::collect_taxonomy_terms( $related_post, 'tag' ) );
            $category_terms = array_merge( $category_terms, self::collect_taxonomy_terms( $related_post, 'category' ) );
        }

        $tag_terms = self::tidy_terms( $tag_terms );
        $category_terms = self::tidy_terms( $category_terms );
        $modifiers = self::merge_modifier_terms(
            self::normalize_term_for_compare( (string) $model_post->post_title ),
            $tag_terms,
            $category_terms
        );

        return [
            'tags' => $tag_terms,
            'categories' => $category_terms,
            'modifiers' => $modifiers,
        ];
    }

    /**
     * @return \WP_Post[]
     */
    private static function find_related_posts_for_model( \WP_Post $model_post ): array {
        $slug = sanitize_title( (string) $model_post->post_name );
        if ( $slug !== '' && function_exists( 'tmw_get_videos_for_model' ) ) {
            $items = tmw_get_videos_for_model( $slug, 40 );
            if ( is_array( $items ) ) {
                $posts = array_filter( $items, static function( $item ): bool {
                    return $item instanceof \WP_Post && $item->post_status === 'publish';
                } );
                if ( ! empty( $posts ) ) {
                    return array_values( $posts );
                }
            }
        }

        $search_phrase = trim( (string) $model_post->post_title );
        if ( $search_phrase === '' ) {
            return [];
        }

        $query = new \WP_Query( [
            'post_type'      => [ 'post' ],
            'post_status'    => 'publish',
            'posts_per_page' => 40,
            's'              => $search_phrase,
            'fields'         => 'all',
            'no_found_rows'  => true,
        ] );

        return array_filter( (array) $query->posts, static function( $post ) {
            return $post instanceof \WP_Post;
        } );
    }

    /**
     * @return array<int,array{group:string,seed:string,intent:string}>
     */
    public static function build_video_seeds( array $context ): array {
        $name      = self::tidy_phrase( (string) ( $context['entity_name'] ?? '' ) );
        $tags      = self::tidy_terms( (array) ( $context['taxonomy_tags'] ?? [] ) );
        $platforms = (array) ( $context['verified_platforms'] ?? [] );

        $seeds = [];
        // Primary descriptor selection: prefer first non-empty tag as descriptor.
        $descriptor = $tags[0] ?? '';

        if ( $name !== '' && $descriptor !== '' ) {
            $seeds[] = [ 'group' => 'name_descriptor', 'seed' => "{$name} {$descriptor}", 'intent' => 'transactional' ];
            $seeds[] = [ 'group' => 'name_descriptor_video', 'seed' => "{$name} {$descriptor} video", 'intent' => 'transactional' ];
            $seeds[] = [ 'group' => 'name_descriptor_clip', 'seed' => "{$name} {$descriptor} clip", 'intent' => 'transactional' ];
            $seeds[] = [ 'group' => 'watch_name_descriptor', 'seed' => "watch {$name} {$descriptor}", 'intent' => 'transactional' ];
        }

        foreach ( $platforms as $platform ) {
            $platform_label = self::tidy_phrase( str_replace( '_', ' ', (string) $platform ) );
            if ( $platform_label === '' || $descriptor === '' ) { continue; }
            $seeds[] = [
                'group'  => 'platform_descriptor_highlights',
                'seed'   => "{$platform_label} {$descriptor} highlights",
                'intent' => 'commercial',
            ];
        }

        // Tag-only "video" seeds are ONLY emitted when the model context exists
        // (rule from the strategy spec). Without a name, "tag video" is too
        // generic for a video page.
        if ( $name !== '' ) {
            foreach ( array_slice( $tags, 0, 2 ) as $tag ) {
                $seeds[] = [
                    'group'  => 'tag_video_with_name_context',
                    'seed'   => "{$name} {$tag} video",
                    'intent' => 'transactional',
                ];
            }
        }

        return self::dedupe_seeds( $seeds );
    }

    /**
     * @return array<int,array{group:string,seed:string,intent:string}>
     */
    public static function build_category_seeds( array $context ): array {
        $category = self::tidy_phrase( (string) ( $context['entity_name'] ?? '' ) );
        if ( $category === '' ) { return []; }

        $seeds = [
            [ 'group' => 'category_models',          'seed' => "{$category} models",          'intent' => 'commercial' ],
            [ 'group' => 'category_webcam_models',   'seed' => "{$category} webcam models",   'intent' => 'commercial' ],
            [ 'group' => 'category_live_cam',        'seed' => "{$category} live cam",        'intent' => 'transactional' ],
            [ 'group' => 'category_video',           'seed' => "{$category} video",           'intent' => 'transactional' ],
            [ 'group' => 'category_profiles',        'seed' => "{$category} profiles",        'intent' => 'navigational' ],
            [ 'group' => 'best_category_pages',      'seed' => "best {$category} cam sites",  'intent' => 'commercial' ],
        ];

        return self::dedupe_seeds( $seeds );
    }

    /**
     * @return array<int,array{group:string,seed:string,intent:string}>
     */
    public static function build_tag_seeds( array $context ): array {
        $tag = self::tidy_phrase( (string) ( $context['entity_name'] ?? '' ) );
        if ( $tag === '' ) { return []; }

        $seeds = [
            [ 'group' => 'tag_models',     'seed' => "{$tag} models",     'intent' => 'commercial' ],
            [ 'group' => 'tag_webcam',     'seed' => "{$tag} webcam",     'intent' => 'commercial' ],
            [ 'group' => 'tag_live_cam',   'seed' => "{$tag} live cam",   'intent' => 'transactional' ],
            [ 'group' => 'tag_video',      'seed' => "{$tag} video",      'intent' => 'transactional' ],
            [ 'group' => 'tag_profiles',   'seed' => "{$tag} profiles",   'intent' => 'navigational' ],
        ];

        return self::dedupe_seeds( $seeds );
    }

    /**
     * Opportunity seeds are competitor/intersection driven by design. We
     * surface the recommended endpoints + a small naming convention that the
     * future paid scan layer will consume.
     *
     * @return array<int,array{group:string,seed:string,intent:string}>
     */
    public static function build_opportunity_seeds( array $context ): array {
        $name       = self::tidy_phrase( (string) ( $context['entity_name'] ?? '' ) );
        $url_group  = (string) ( $context['url_pattern_group'] ?? '/' );
        $seeds      = [];

        if ( $name !== '' ) {
            $seeds[] = [ 'group' => 'opportunity_seed', 'seed' => $name, 'intent' => 'commercial' ];
        }

        $tags = self::tidy_terms( (array) ( $context['taxonomy_tags'] ?? [] ) );
        foreach ( array_slice( $tags, 0, 5 ) as $tag ) {
            $seeds[] = [ 'group' => 'opportunity_tag', 'seed' => $tag, 'intent' => 'commercial' ];
        }

        // Pseudo-seed: the URL pattern itself is the discovery vector for
        // page_intersection / relevant_pages. We surface it so the preview
        // plan can show the operator what will be queried.
        if ( $url_group !== '' && $url_group !== '/' ) {
            $seeds[] = [
                'group'  => 'url_pattern_vector',
                'seed'   => $url_group . '*',
                'intent' => 'gap_discovery',
            ];
        }

        return self::dedupe_seeds( $seeds );
    }

    /**
     * Recommended DataForSEO endpoints per page type, in the order they
     * should be invoked when the future paid-scan layer is wired up. The
     * preview plan surfaces this list to the operator so they can see the
     * query budget shape BEFORE spending credits.
     *
     * @return array<int,string>
     */
    public static function get_endpoint_plan_for_page_type( string $page_type ): array {
        switch ( $page_type ) {
            case self::PAGE_TYPE_MODEL:
                return [
                    'dataforseo_labs/google/keyword_suggestions/live',
                    'dataforseo_labs/google/keyword_ideas/live',
                    'dataforseo_labs/google/search_intent/live',
                    'dataforseo_labs/google/keyword_overview/live',
                    'dataforseo_labs/google/page_intersection/live',
                    'serp/google/organic/live/advanced',
                ];

            case self::PAGE_TYPE_VIDEO:
                return [
                    'keywords_data/google_ads/keywords_for_keywords/live',
                    'dataforseo_labs/google/keyword_overview/live',
                    'dataforseo_labs/google/search_intent/live',
                    'dataforseo_labs/google/relevant_pages/live',
                ];

            case self::PAGE_TYPE_CATEGORY:
                return [
                    'dataforseo_labs/google/keyword_ideas/live',
                    'dataforseo_labs/google/related_keywords/live',
                    'dataforseo_labs/google/search_intent/live',
                    'dataforseo_labs/google/relevant_pages/live',
                    'dataforseo_labs/google/keyword_overview/live',
                ];

            case self::PAGE_TYPE_TAG:
                return [
                    'dataforseo_labs/google/keyword_ideas/live',
                    'keywords_data/google_ads/search_volume/live',
                    'dataforseo_labs/google/search_intent/live',
                    'serp/google/organic/live/advanced',
                ];

            case self::PAGE_TYPE_OPPORTUNITY:
                return [
                    'dataforseo_labs/google/serp_competitors/live',
                    'dataforseo_labs/google/competitors_domain/live',
                    'dataforseo_labs/google/domain_intersection/live',
                    'dataforseo_labs/google/page_intersection/live',
                    'dataforseo_labs/google/relevant_pages/live',
                    'dataforseo_labs/google/ranked_keywords/live',
                    'dataforseo_labs/google/keyword_overview/live',
                    'serp/google/organic/live/advanced',
                ];
        }
        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Candidate-shape heuristic
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lightweight heuristic for the SHAPE of a candidate keyword in the
     * supplied context. This is NOT a final ranking probability score — it
     * is intentionally separate from RankingProbabilityEngine and is meant
     * for preview UIs to show "how well does this keyword fit this page
     * type before we even query the API".
     *
     * Range: 0.0 .. 1.0
     *
     * @return array{
     *   score:float,
     *   length_words:int,
     *   longtail_quality:float,
     *   entity_match:float,
     *   page_type_fit:float,
     *   notes:string[]
     * }
     */
    public static function score_candidate_shape( string $keyword, array $context ): array {
        $kw = mb_strtolower( trim( $keyword ), 'UTF-8' );
        $notes = [];

        if ( $kw === '' ) {
            return [
                'score'            => 0.0,
                'length_words'     => 0,
                'longtail_quality' => 0.0,
                'entity_match'     => 0.0,
                'page_type_fit'    => 0.0,
                'notes'            => [ 'empty_keyword' ],
            ];
        }

        $words = preg_split( '/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY );
        $word_count = is_array( $words ) ? count( $words ) : 0;

        // Longtail quality: prefer 3..6 words.
        $longtail_quality = 0.0;
        if ( $word_count >= 3 && $word_count <= 6 ) {
            $longtail_quality = 1.0;
        } elseif ( $word_count === 2 || $word_count === 7 ) {
            $longtail_quality = 0.6;
        } elseif ( $word_count === 1 || $word_count === 8 ) {
            $longtail_quality = 0.3;
        } else {
            $longtail_quality = 0.1;
        }

        $page_type = (string) ( $context['page_type'] ?? '' );

        // Entity match: 1.0 if name/handle/platform/category/tag present.
        $entity_match = self::measure_entity_match( $kw, $context );

        // Page-type fit: respects the hard rules from the strategy spec.
        $page_type_fit = self::measure_page_type_fit( $kw, $context, $notes );

        $score = (
            ( 0.40 * $entity_match )
            + ( 0.35 * $page_type_fit )
            + ( 0.25 * $longtail_quality )
        );
        $score = max( 0.0, min( 1.0, $score ) );

        return [
            'score'            => round( $score, 4 ),
            'length_words'     => $word_count,
            'longtail_quality' => round( $longtail_quality, 4 ),
            'entity_match'     => round( $entity_match, 4 ),
            'page_type_fit'    => round( $page_type_fit, 4 ),
            'notes'            => $notes,
        ];
    }

    private static function measure_entity_match( string $kw, array $context ): float {
        $name = mb_strtolower( (string) ( $context['entity_name'] ?? '' ), 'UTF-8' );
        if ( $name !== '' && strpos( $kw, $name ) !== false ) {
            return 1.0;
        }
        foreach ( (array) ( $context['verified_platforms'] ?? [] ) as $platform ) {
            $p = mb_strtolower( (string) $platform, 'UTF-8' );
            if ( $p !== '' && strpos( $kw, $p ) !== false ) {
                return 0.7;
            }
        }
        foreach ( (array) ( $context['verified_handles'] ?? [] ) as $handle_pair ) {
            if ( ! is_string( $handle_pair ) || strpos( $handle_pair, ':' ) === false ) { continue; }
            [ , $handle ] = array_pad( explode( ':', $handle_pair, 2 ), 2, '' );
            $h = mb_strtolower( (string) $handle, 'UTF-8' );
            if ( $h !== '' && strpos( $kw, $h ) !== false ) {
                return 0.8;
            }
        }
        foreach ( (array) ( $context['taxonomy_tags'] ?? [] ) as $tag ) {
            $t = mb_strtolower( str_replace( '-', ' ', (string) $tag ), 'UTF-8' );
            if ( $t !== '' && strpos( $kw, $t ) !== false ) {
                return 0.4;
            }
        }
        return 0.0;
    }

    private static function measure_page_type_fit( string $kw, array $context, array &$notes ): float {
        $page_type = (string) ( $context['page_type'] ?? '' );
        $name      = mb_strtolower( (string) ( $context['entity_name'] ?? '' ), 'UTF-8' );

        switch ( $page_type ) {

            case self::PAGE_TYPE_MODEL:
                // Model pages MUST contain name OR handle OR platform context.
                $has_name = ( $name !== '' && strpos( $kw, $name ) !== false );
                $has_platform = false;
                foreach ( (array) ( $context['verified_platforms'] ?? [] ) as $platform ) {
                    if ( strpos( $kw, mb_strtolower( (string) $platform, 'UTF-8' ) ) !== false ) {
                        $has_platform = true;
                        break;
                    }
                }
                if ( ! $has_name && ! $has_platform ) {
                    $notes[] = 'rejected_pure_tag_only_for_model_page';
                    return 0.0;
                }
                return $has_name ? 1.0 : 0.65;

            case self::PAGE_TYPE_VIDEO:
                // Video pages reject generic "video"/"clip" without model or descriptor.
                $is_generic_video = preg_match( '/^(video|clip|cam video)$/i', $kw );
                if ( $is_generic_video ) {
                    $notes[] = 'rejected_generic_video_keyword';
                    return 0.0;
                }
                $has_name = ( $name !== '' && strpos( $kw, $name ) !== false );
                $has_descriptor = false;
                foreach ( (array) ( $context['taxonomy_tags'] ?? [] ) as $tag ) {
                    $t = mb_strtolower( str_replace( '-', ' ', (string) $tag ), 'UTF-8' );
                    if ( $t !== '' && strpos( $kw, $t ) !== false ) {
                        $has_descriptor = true;
                        break;
                    }
                }
                if ( ! $has_name && ! $has_descriptor ) {
                    $notes[] = 'video_keyword_lacks_model_or_descriptor';
                    return 0.2;
                }
                return ( $has_name && $has_descriptor ) ? 1.0 : 0.6;

            case self::PAGE_TYPE_CATEGORY:
                // Category pages MUST avoid exact model-name queries.
                if ( self::keyword_looks_like_model_name( $kw ) ) {
                    $notes[] = 'rejected_model_name_on_category_page';
                    return 0.1;
                }
                if ( self::has_listing_intent_token( $kw ) ) {
                    return 1.0;
                }
                return 0.5;

            case self::PAGE_TYPE_TAG:
                // Tag pages avoid hyper-generic adult terms with no listing fit.
                $has_listing = self::has_listing_intent_token( $kw );
                $is_hyper_generic_only = false;
                foreach ( self::HYPER_GENERIC_TAG_TOKENS as $bad ) {
                    if ( $kw === $bad ) {
                        $is_hyper_generic_only = true;
                        break;
                    }
                }
                if ( $is_hyper_generic_only && ! $has_listing ) {
                    $notes[] = 'rejected_hyper_generic_tag_without_listing_intent';
                    return 0.0;
                }
                return $has_listing ? 1.0 : 0.5;

            case self::PAGE_TYPE_OPPORTUNITY:
                // Broader; intent score happens later. Just demand something
                // beyond a single token.
                $word_count = (int) substr_count( $kw, ' ' ) + 1;
                if ( $word_count <= 1 ) {
                    return 0.3;
                }
                return 0.85;
        }

        return 0.4;
    }

    private static function has_listing_intent_token( string $kw ): bool {
        foreach ( self::LISTING_INTENT_TOKENS as $token ) {
            if ( strpos( $kw, $token ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function keyword_looks_like_model_name( string $kw ): bool {
        // Heuristic: two short capitalisable words at the start (we lower-cased
        // earlier, so we test the structural shape only). This is a very weak
        // signal; the real owner check belongs to OwnershipEnforcer in a later
        // pass. For preview purposes we just flag obvious "Firstname Lastname"
        // patterns.
        if ( ! preg_match( '/^[a-z]{2,}\s+[a-z]{2,}$/u', $kw ) ) {
            return false;
        }
        // Listing tokens "X models" / "X cams" should NOT be flagged.
        if ( self::has_listing_intent_token( $kw ) ) {
            return false;
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Preview plan (dry-run)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a complete preview plan for a given post. Pure read-only —
     * no API calls, no DB writes.
     */
    public static function build_preview_plan_for_post( int $post_id ): array {
        $context = self::build_context_from_post( $post_id );
        return self::build_preview_plan_for_page_type( $context['page_type'], $context );
    }

    /**
     * Build a preview plan for an arbitrary page-type + context. Useful for
     * opportunity pages that don't always live in a single post, and for
     * unit tests and CLI tooling.
     */
    public static function build_preview_plan_for_page_type( string $page_type, array $context ): array {
        // Allow the caller to pass a sparse context — fill in defaults so the
        // builders don't have to special-case missing keys.
        // We deliberately reset the empty-context "invalid_post" warning here:
        // it's only meaningful when build_context_from_post() failed, not when
        // a caller is constructing a context by hand for opportunity pages,
        // CLI tooling, or unit tests.
        $defaults = self::empty_context();
        $defaults['warnings'] = [];
        $context = array_merge( $defaults, $context );
        if ( $page_type !== '' ) {
            $context['page_type'] = $page_type;
        }

        // Recompute warnings from the merged context so they reflect what the
        // caller actually provided, not the empty defaults.
        $context['warnings'] = self::collect_warnings( $context );

        $seeds = [];
        $candidate_types = [];

        switch ( $context['page_type'] ) {
            case self::PAGE_TYPE_MODEL:
                $seeds = self::build_model_seeds( $context );
                $candidate_types = [ 'navigational_brand', 'name_platform', 'name_tag', 'transactional_live' ];
                break;
            case self::PAGE_TYPE_VIDEO:
                $seeds = self::build_video_seeds( $context );
                $candidate_types = [ 'name_descriptor_video', 'platform_descriptor_highlights' ];
                break;
            case self::PAGE_TYPE_CATEGORY:
                $seeds = self::build_category_seeds( $context );
                $candidate_types = [ 'category_listing', 'best_category_pages' ];
                break;
            case self::PAGE_TYPE_TAG:
                $seeds = self::build_tag_seeds( $context );
                $candidate_types = [ 'tag_listing', 'tag_video' ];
                break;
            case self::PAGE_TYPE_OPPORTUNITY:
                $seeds = self::build_opportunity_seeds( $context );
                $candidate_types = [ 'competitor_gap', 'page_intersection', 'ranked_overlap' ];
                break;
            default:
                // Unknown page type → empty plan, surfaced via warnings.
                break;
        }

        $endpoint_plan = self::get_endpoint_plan_for_page_type( $context['page_type'] );

        $plan = [
            'page_type'           => $context['page_type'],
            'entity_name'         => $context['entity_name'],
            'post_id'             => (int) ( $context['post_id'] ?? 0 ),
            'permalink'           => (string) ( $context['permalink'] ?? '' ),
            'url_pattern_group'   => (string) ( $context['url_pattern_group'] ?? '/' ),
            'seed_groups'         => $seeds,
            'seed_count'          => count( $seeds ),
            'recommended_endpoints' => $endpoint_plan,
            'estimated_candidate_types' => $candidate_types,
            'verified_platforms'  => (array) ( $context['verified_platforms'] ?? [] ),
            'verified_handles'    => (array) ( $context['verified_handles'] ?? [] ),
            'taxonomy_tags'       => (array) ( $context['taxonomy_tags'] ?? [] ),
            'taxonomy_categories' => (array) ( $context['taxonomy_categories'] ?? [] ),
            'related_content_tags' => (array) ( $context['related_content_tags'] ?? [] ),
            'related_content_categories' => (array) ( $context['related_content_categories'] ?? [] ),
            'modifier_terms'      => (array) ( $context['modifier_terms'] ?? [] ),
            'has_strong_entity'   => (bool) ( $context['has_strong_entity'] ?? false ),
            'warnings'            => array_values( array_unique( array_merge(
                (array) ( $context['warnings'] ?? [] ),
                self::plan_level_warnings( $context, $seeds )
            ) ) ),
            'notes'               => [
                'dry_run'                 => true,
                'no_api_calls_performed'  => true,
                'no_db_writes_performed'  => true,
                'foundation_pass_version' => '5.9.0-dfseo-foundation',
            ],
        ];

        return $plan;
    }

    private static function plan_level_warnings( array $context, array $seeds ): array {
        $warnings = [];
        if ( empty( $seeds ) ) {
            $warnings[] = 'no_seeds_built';
        }
        if ( $context['page_type'] === self::PAGE_TYPE_MODEL && empty( $context['verified_platforms'] ) ) {
            $warnings[] = 'model_seed_quality_low_without_platforms';
        }
        if ( $context['page_type'] === self::PAGE_TYPE_VIDEO && empty( $context['taxonomy_tags'] ) ) {
            $warnings[] = 'video_seed_quality_low_without_descriptor_tags';
        }
        if ( $context['page_type'] === self::PAGE_TYPE_OPPORTUNITY
             && empty( $context['entity_name'] )
             && empty( $context['taxonomy_tags'] ) ) {
            $warnings[] = 'opportunity_context_too_thin';
        }
        return $warnings;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Future schema planning (Phase D — DOCUMENTATION ONLY).
    //
    // The following arrays describe the run-ledger DB schema that will be
    // introduced in a future pass. NO migration runs in this pass.
    // NO existing tables are touched. The structure is exposed here so
    // operators / tooling can preview the eventual contract.
    //
    // TODO(future-pass): create these tables via includes/db/class-schema.php
    //   together with run-aware writers in the discovery and SERP layers.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns the planned (not-yet-created) run-ledger schema as a
     * descriptive array. Pure documentation — does not touch the DB.
     */
    public static function get_planned_run_ledger_schema(): array {
        return [
            'pass_version' => '5.9.0-dfseo-foundation',
            'status'       => 'planned_not_yet_migrated',
            'tables' => [
                'tmwseo_scan_runs' => [
                    'id', 'run_uuid', 'scan_type', 'page_type', 'entity_type',
                    'entity_id', 'source', 'started_at', 'finished_at', 'status',
                    'estimated_cost_usd', 'actual_cost_usd', 'location_code',
                    'language_code', 'operator_user_id',
                ],
                'tmwseo_scan_tasks' => [
                    'id', 'run_id', 'endpoint', 'payload_json', 'status_code',
                    'status_message', 'cost_usd', 'result_count', 'started_at',
                    'finished_at',
                ],
                'tmwseo_scan_items' => [
                    'id', 'run_id', 'endpoint', 'keyword', 'source_type',
                    'raw_json', 'filter_stage', 'filter_reason', 'accepted',
                ],
                'tmwseo_candidate_versions' => [
                    'id', 'candidate_id', 'run_id', 'keyword', 'intent',
                    'volume', 'difficulty', 'page_type_fit', 'entity_match',
                    'serp_weakness', 'score', 'decision',
                ],
                'tmwseo_serp_observations' => [
                    'id', 'run_id', 'keyword', 'serp_item_types_json',
                    'organic_count', 'forum_count', 'video_count',
                    'ai_overview_present', 'paa_present', 'sample_urls_json',
                ],
                'tmwseo_onpage_observations' => [
                    'id', 'run_id', 'url', 'plain_text_word_count',
                    'title_length', 'description_length', 'h1_count',
                    'internal_links', 'keyword_density_json', 'timing_json',
                    'checks_json',
                ],
            ],
            'notes' => [
                'no_db_changes_this_pass',
                'no_alter_table_this_pass',
                'no_dbdelta_this_pass',
                'foundation_only_advisory_schema',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private static function tidy_phrase( string $value ): string {
        $value = str_replace( [ '_', '-' ], ' ', $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( mb_strtolower( (string) $value, 'UTF-8' ) );
    }

    /**
     * @param string[] $terms
     * @return string[]
     */
    private static function tidy_terms( array $terms ): array {
        $out = [];
        foreach ( $terms as $term ) {
            $clean = self::tidy_phrase( (string) $term );
            if ( $clean !== '' ) {
                $out[] = $clean;
            }
        }
        return array_values( array_unique( $out ) );
    }

    private static function normalize_term_for_compare( string $value ): string {
        return preg_replace( '/[^a-z0-9]+/u', '', self::tidy_phrase( $value ) ) ?: '';
    }

    /**
     * @param string   $entity_norm
     * @param string[] ...$groups
     * @return string[]
     */
    private static function merge_modifier_terms( string $entity_norm, array ...$groups ): array {
        $scored = [];
        $seen = [];
        foreach ( $groups as $group ) {
            foreach ( self::tidy_terms( $group ) as $term ) {
                $norm = self::normalize_term_for_compare( $term );
                if ( $norm === '' || $norm === $entity_norm || $term === 'uncategorized' ) { continue; }
                if ( isset( $seen[ $norm ] ) ) { continue; }
                $seen[ $norm ] = true;
                $scored[] = [
                    'term'  => $term,
                    'score' => self::modifier_priority_score( $term ),
                ];
            }
        }
        usort(
            $scored,
            static function ( array $a, array $b ): int {
                if ( (int) $a['score'] === (int) $b['score'] ) {
                    return strcmp( (string) $a['term'], (string) $b['term'] );
                }
                return ( (int) $b['score'] <=> (int) $a['score'] );
            }
        );
        $out = array_map(
            static function ( array $item ): string {
                return (string) $item['term'];
            },
            $scored
        );
        return array_slice( $out, 0, 30 );
    }

    private static function modifier_priority_score( string $term ): int {
        $score = 0;
        $words = preg_split( '/\s+/u', trim( $term ) ) ?: [];
        $word_count = count( array_filter( $words ) );

        if ( $word_count >= 4 ) {
            $score += 80;
        } elseif ( $word_count === 3 ) {
            $score += 60;
        } elseif ( $word_count === 2 ) {
            $score += 35;
        } elseif ( $word_count === 1 ) {
            $score += 5;
        }

        $priority_patterns = [
            'live cam'      => 70,
            'cam girl'      => 65,
            'live sex'      => 65,
            'livejasmin'    => 55,
            'stripchat'     => 55,
            'chaturbate'    => 55,
            'onlyfans'      => 45,
            'big tits'      => 45,
            'black hair'    => 40,
            'blonde'        => 35,
            'brunette'      => 35,
            'tattoo'        => 30,
        ];

        foreach ( $priority_patterns as $needle => $weight ) {
            if ( strpos( $term, $needle ) !== false ) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * Prefer phrase modifiers for platform combos ("live cam", "cam girl", etc).
     */
    private static function best_platform_modifier_combo( string $platform_label, array $modifiers ): string {
        $priority_phrases = [ 'live cam', 'cam girl', 'live sex', 'big tits cam girls', 'big tits cam girl' ];
        foreach ( $priority_phrases as $phrase ) {
            if ( in_array( $phrase, $modifiers, true ) ) {
                return $phrase;
            }
        }
        return $modifiers[0] ?? 'live cam';
    }

    /**
     * @param array<int,array{group:string,seed:string,intent:string}> $seeds
     * @return array<int,array{group:string,seed:string,intent:string}>
     */
    private static function dedupe_seeds( array $seeds ): array {
        $seen = [];
        $out  = [];
        foreach ( $seeds as $seed ) {
            $key = ( $seed['group'] ?? '' ) . '|' . ( $seed['seed'] ?? '' );
            if ( $key === '|' ) { continue; }
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $out[] = $seed;
        }
        return $out;
    }
}
