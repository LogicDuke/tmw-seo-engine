<?php
/**
 * CuratedKeywordLibrary — reads the 30-category CSV keyword library
 * shipped with the plugin (data/keywords/).
 *
 * Provides free, instant seed keywords for the intelligence pipeline
 * without any API calls.
 *
 * @package TMWSEO\Engine\Keywords
 */
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CuratedKeywordLibrary {

    /** Types of CSV files per category. */
    const TYPES = [ 'extra', 'longtail', 'competitor' ];

    /** Path to the bundled keyword data. */
    public static function plugin_base_dir(): string {
        return rtrim( TMWSEO_ENGINE_PATH, '/' ) . '/data/keywords';
    }

    /** Path inside wp-uploads (user-customisable / auto-expanded). */
    public static function uploads_base_dir(): string {
        $uploads = wp_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . 'tmwseo-engine-keywords';
    }

    // ── Category helpers ───────────────────────────────────────────────────

    /**
     * Returns available category slugs (from bundled data dir).
     *
     * @return string[]
     */
    public static function categories(): array {
        $base = self::plugin_base_dir();
        if ( ! is_dir( $base ) ) {
            return [];
        }
        $dirs = glob( $base . '/*', GLOB_ONLYDIR );
        if ( ! $dirs ) {
            return [];
        }
        return array_map( 'basename', $dirs );
    }

    /**
     * FINDING-12: Returns only categories that have at least one non-empty CSV.
     * Most bundled categories shipped as 8-byte stubs (empty headers only).
     * Callers using this avoid silently getting zero keyword results.
     *
     * @return array{ slug: string, keywords: int }[]
     */
    public static function populated_categories(): array {
        $result = [];
        foreach ( self::categories() as $category ) {
            $count = count( self::load_all_for_category( $category ) );
            if ( $count > 0 ) {
                $result[] = [ 'slug' => $category, 'keywords' => $count ];
            }
        }
        usort( $result, static fn( $a, $b ) => $b['keywords'] <=> $a['keywords'] );
        return $result;
    }

    /**
     * FINDING-12: Returns category slugs with empty/stub CSVs.
     * Useful for surfacing admin notices about unpopulated categories.
     *
     * @return string[]
     */
    public static function empty_categories(): array {
        $empty = [];
        foreach ( self::categories() as $category ) {
            if ( count( self::load_all_for_category( $category ) ) === 0 ) {
                $empty[] = $category;
            }
        }
        return $empty;
    }

    // ── CSV reading ────────────────────────────────────────────────────────

    /**
     * Loads all keywords for a single category / type combination.
     *
     * Prefers the uploads directory (user-enriched) over the bundled copy.
     *
     * @param string $category  Category slug, e.g. 'blonde'.
     * @param string $type      'extra' | 'longtail' | 'competitor'
     * @return string[]
     */
    public static function load( string $category, string $type = 'extra' ): array {
        $category = sanitize_title( $category );
        $type     = sanitize_key( $type );
        if ( $category === '' || ! in_array( $type, self::TYPES, true ) ) {
            return [];
        }

        $uploads_path = self::uploads_base_dir() . "/{$category}/{$type}.csv";
        $plugin_path  = self::plugin_base_dir() . "/{$category}/{$type}.csv";

        $path = file_exists( $uploads_path ) ? $uploads_path : $plugin_path;
        if ( ! file_exists( $path ) ) {
            return [];
        }

        return self::read_keywords_from_csv( $path );
    }

    /**
     * Loads keywords for a category across all types.
     *
     * @return string[]
     */
    public static function load_all_for_category( string $category ): array {
        $all = [];
        foreach ( self::TYPES as $type ) {
            $all = array_merge( $all, self::load( $category, $type ) );
        }
        return array_values( array_unique( $all ) );
    }

    /**
     * Provides seed keywords for the intelligence runner.
     *
     * Maps a model's tag/category context to curated seeds.
     * Falls back to 'general' if no specific category matches.
     *
     * @param string[] $tags     Model's tag list or category slugs.
     * @param int      $limit    Max seeds to return.
     * @return string[]
     */
    public static function get_seeds_for_tags( array $tags, int $limit = 20 ): array {
        $categories = self::categories();
        $matched    = [];

        foreach ( $tags as $tag ) {
            $slug = sanitize_title( $tag );
            if ( in_array( $slug, $categories, true ) ) {
                $matched[] = $slug;
            }
        }

        // Fallback to general
        if ( empty( $matched ) ) {
            $matched = [ 'general' ];
        }

        $seeds = [];
        foreach ( $matched as $cat ) {
            // Prefer 'extra' type for seed diversity
            $kws   = self::load( $cat, 'extra' );
            $seeds = array_merge( $seeds, $kws );
            if ( count( $seeds ) >= $limit * 2 ) {
                break;
            }
        }

        shuffle( $seeds );
        return array_slice( array_values( array_unique( $seeds ) ), 0, $limit );
    }

    /**
     * Returns the category-specific modifier seeds from category-seed-patterns.php.
     *
     * @param string $category
     * @return array{seeds:string[],modifiers:string[],suffixes:string[]}
     */
    public static function get_seed_patterns( string $category ): array {
        $file = rtrim( TMWSEO_ENGINE_PATH, '/' ) . '/data/category-seed-patterns.php';
        if ( ! file_exists( $file ) ) {
            return [ 'seeds' => [], 'modifiers' => [], 'suffixes' => [] ];
        }
        $patterns = include $file;
        $slug     = sanitize_title( $category );
        if ( isset( $patterns[ $slug ] ) && is_array( $patterns[ $slug ] ) ) {
            return $patterns[ $slug ];
        }
        return [ 'seeds' => [], 'modifiers' => [], 'suffixes' => [] ];
    }

    /**
     * Returns curated seeds for a category from curated-seeds.php.
     *
     * @param string $category
     * @return string[]
     */
    public static function get_curated_seeds( string $category ): array {
        $file = rtrim( TMWSEO_ENGINE_PATH, '/' ) . '/data/curated-seeds.php';
        if ( ! file_exists( $file ) ) {
            return [];
        }
        $seeds = include $file;
        $slug  = sanitize_title( $category );
        return isset( $seeds[ $slug ] ) && is_array( $seeds[ $slug ] ) ? $seeds[ $slug ] : [];
    }

    // ── Niche pattern family methods (builder layer) ────────────────────────

    /**
     * Returns all niche/descriptor pattern families (builder layer only).
     *
     * These are NOT trusted roots. Output from this layer must be routed to
     * the candidate/preview layer (ExpansionCandidateRepository), never
     * directly to SeedRegistry::register_trusted_seed().
     *
     * @return array{
     *   string: array{
     *     templates: string[],
     *     descriptors: array<string, string[]>
     *   }
     * }
     */
    public static function get_niche_pattern_families(): array {
        $file = rtrim( TMWSEO_ENGINE_PATH, '/' ) . '/data/niche-pattern-families.php';
        if ( ! file_exists( $file ) ) {
            return [];
        }
        $families = include $file;
        return is_array( $families ) ? $families : [];
    }

    /**
     * Generates candidate phrases for a category from niche pattern families.
     *
     * !! BUILDER-ONLY METHOD !!
     * Output is for candidate/preview generation — NOT for direct registration
     * as trusted seeds. Route output through:
     *   SeedRegistry::register_candidate_phrase()
     *   ExpansionCandidateRepository::insert_candidate()
     *
     * @param string $category  Category slug, e.g. 'blonde', 'asian', 'milf'.
     * @param int    $limit     Max candidates returned (0 = no limit).
     * @return string[]         Lowercase candidate phrases, deduplicated.
     */
    public static function generate_builder_candidates( string $category, int $limit = 50 ): array {
        $slug     = sanitize_title( $category );
        $families = self::get_niche_pattern_families();
        $phrases  = [];

        foreach ( $families as $branch ) {
            if ( ! isset( $branch['templates'], $branch['descriptors'] ) ) {
                continue;
            }
            if ( ! isset( $branch['descriptors'][ $slug ] ) ) {
                continue;
            }
            $descriptors = (array) $branch['descriptors'][ $slug ];
            $templates   = (array) $branch['templates'];
            foreach ( $descriptors as $descriptor ) {
                foreach ( $templates as $tpl ) {
                    $phrase = str_replace( '[d]', (string) $descriptor, (string) $tpl );
                    $phrase = mb_strtolower( (string) preg_replace( '/\s+/', ' ', trim( $phrase ) ), 'UTF-8' );
                    if ( $phrase !== '' ) {
                        $phrases[] = $phrase;
                    }
                }
            }
            // A given category-slug belongs to exactly one branch.
            break;
        }

        $phrases = array_values( array_unique( $phrases ) );
        if ( $limit > 0 ) {
            $phrases = array_slice( $phrases, 0, $limit );
        }
        return $phrases;
    }

    /**
     * Returns all category slugs that have a niche pattern family entry.
     *
     * Useful for batch jobs that need to know which categories can produce
     * builder candidates without manually enumerating pattern families.
     *
     * @return string[]
     */
    public static function niche_pattern_categories(): array {
        $families = self::get_niche_pattern_families();
        $cats     = [];
        foreach ( $families as $branch ) {
            if ( isset( $branch['descriptors'] ) && is_array( $branch['descriptors'] ) ) {
                foreach ( array_keys( $branch['descriptors'] ) as $cat ) {
                    $cats[] = (string) $cat;
                }
            }
        }
        return array_values( array_unique( $cats ) );
    }

    // ── Internal CSV reader ────────────────────────────────────────────────

    private static function read_keywords_from_csv( string $path ): array {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) {
            return [];
        }

        $keywords    = [];
        $header_cols = [];

        $first_row = fgetcsv( $fh );
        if ( $first_row !== false && is_array( $first_row ) ) {
            $normalized = array_map( function( $col ) {
                return strtolower( trim( (string) $col ) );
            }, $first_row );
            foreach ( $normalized as $i => $col ) {
                if ( in_array( $col, [ 'keyword', 'phrase' ], true ) ) {
                    $header_cols[] = $i;
                }
            }
            if ( empty( $header_cols ) ) {
                // No header row — treat first row as data
                foreach ( $first_row as $i => $val ) {
                    $val = trim( (string) $val );
                    if ( $val !== '' ) {
                        $keywords[] = $val;
                    }
                }
            }
        }

        if ( empty( $header_cols ) ) {
            $header_cols = [ 0 ];
        }

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            foreach ( $header_cols as $col_index ) {
                if ( ! isset( $row[ $col_index ] ) ) {
                    continue;
                }
                $val = trim( (string) $row[ $col_index ] );
                if ( $val !== '' ) {
                    $keywords[] = $val;
                }
            }
        }
        fclose( $fh );

        return array_values( array_unique( array_filter( $keywords, 'strlen' ) ) );
    }
}
