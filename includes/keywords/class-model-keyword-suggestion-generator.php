<?php
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ModelKeywordSuggestionGenerator {

    private const DISALLOWED_ATTRIBUTES = [
        'teen','young','underage','schoolgirl','school','student','child','kids',
        'leaked','leaks','torrent','download','reddit','discord','telegram','onlyfans leak',
        'webcam driver','security camera','surveillance','zoom','teams','logitech',
        'porn','nude','naked','sex','xxx','anal','blowjob','hardcore',
    ];

    private const MODEL_NAME_PATTERNS = [
        '{model} live cam girl','{model} cam model','{model} adult video webcam chat','{model} live cam show',
        '{model} adult video chat model','{model} webcam chat model','{model} adult webcam model','{model} private cam chat',
        '{model} live video chat girl','{model} live cam model','{model} webcam chat girl','{model} adult cam show',
        '{model} private video chat','{model} live webcam model','{model} webcam video chat','{model} private cam show',
        '{model} adult live chat model','{model} live webcam chat','{model} adult cam model','{model} private video chat girl',
        '{model} webcam model profile',
    ];


    private const DESCRIPTIVE_HINTS = [
        'brunette','blonde','latina','asian','ebony','mature','curvy','petite','tattooed','lingerie',
        'solo','natural','webcam','private show','live chat','bbw','milf','redhead','big tits','small tits',
    ];

    private const ATTRIBUTE_PATTERNS = [
        '{attribute} adult video chat model','{attribute} live cam girl','{attribute} webcam chat model','{attribute} adult webcam model',
        '{attribute} private cam show','{attribute} live webcam chat','{attribute} adult cam model','{attribute} webcam video chat',
        '{attribute} live cam show','{attribute} adult live chat model',
    ];

    public function generate_for_model( \WP_Post $post, bool $include_tags = true, bool $include_categories = true ): array {
        $model_name = trim( (string) $post->post_title );
        if ( $model_name === '' ) {
            $model_name = 'Model ' . (int) $post->ID;
        }

        $all_taxonomies = $this->taxonomies_for_model_preview();
        $tag_taxonomies = array_values( array_intersect( [ 'post_tag', 'models' ], $all_taxonomies ) );
        $category_taxonomies = array_values( array_diff( $all_taxonomies, $tag_taxonomies ) );

        $direct_tags       = $include_tags ? $this->collect_terms( $post->ID, $tag_taxonomies ) : [];
        $direct_categories = $include_categories ? $this->collect_terms( $post->ID, $category_taxonomies ) : [];
        $related           = $this->collect_related_frontend_terms( $post );
        $related_tags      = $include_tags ? $related['tags'] : [];
        $related_categories = $include_categories ? $related['categories'] : [];

        $ignored_tag_terms = [];
        $ignored_category_terms = [];

        $tag_attributes = $include_tags
            ? $this->filter_descriptive_attributes( array_values( array_unique( array_merge( $direct_tags, $related_tags ) ) ), $model_name, $ignored_tag_terms )
            : [];
        $category_attributes = $include_categories
            ? $this->filter_descriptive_attributes( array_values( array_unique( array_merge( $direct_categories, $related_categories ) ) ), $model_name, $ignored_category_terms )
            : [];

        $extra = $this->build_extra_keywords( (int) $post->ID, $model_name, $tag_attributes, $category_attributes, $include_tags, $include_categories );

        return [
            'post_id'         => (int) $post->ID,
            'model_name'      => $model_name,
            'primary_keyword' => $model_name,
            'extra_keywords'  => $extra,
            'direct_tags'     => $direct_tags,
            'direct_categories' => $direct_categories,
            'related_tags'    => $related_tags,
            'related_categories' => $related_categories,
            'ignored_terms'   => array_values( array_unique( array_merge( $ignored_tag_terms, $ignored_category_terms ) ) ),
        ];
    }

    /**
     * @return array{tags:string[],categories:string[]}
     */
    private function collect_related_frontend_terms( \WP_Post $post ): array {
        $related_posts = $this->find_related_posts_for_model( $post );
        if ( empty( $related_posts ) ) {
            return [ 'tags' => [], 'categories' => [] ];
        }

        $tags = [];
        $categories = [];
        foreach ( $related_posts as $related_post ) {
            $tags = array_merge( $tags, $this->collect_terms_by_post_kind( $related_post, 'tag' ) );
            $categories = array_merge( $categories, $this->collect_terms_by_post_kind( $related_post, 'category' ) );
        }

        return [
            'tags' => array_values( array_unique( $tags ) ),
            'categories' => array_values( array_unique( $categories ) ),
        ];
    }

    /**
     * @return \WP_Post[]
     */
    private function find_related_posts_for_model( \WP_Post $post ): array {
        $slug = sanitize_title( (string) $post->post_name );
        if ( $slug !== '' && function_exists( 'tmw_get_videos_for_model' ) ) {
            $items = tmw_get_videos_for_model( $slug, 40 );
            if ( is_array( $items ) ) {
                $related = array_filter( $items, static function( $item ): bool {
                    return $item instanceof \WP_Post && $item->post_status === 'publish';
                } );
                if ( ! empty( $related ) ) {
                    return array_values( $related );
                }
            }
        }

        $query = new \WP_Query( [
            'post_type' => [ 'post' ],
            'post_status' => 'publish',
            'posts_per_page' => 40,
            's' => trim( (string) $post->post_title ),
            'fields' => 'all',
            'no_found_rows' => true,
        ] );
        return array_values( array_filter( (array) $query->posts, static function( $item ): bool {
            return $item instanceof \WP_Post;
        } ) );
    }

    private function collect_terms_by_post_kind( \WP_Post $post, string $kind ): array {
        $taxonomies = (array) get_object_taxonomies( $post->post_type );
        $names = [];
        foreach ( $taxonomies as $taxonomy ) {
            $taxonomy_object = get_taxonomy( $taxonomy );
            if ( ! $taxonomy_object ) {
                continue;
            }
            $is_hierarchical = ! empty( $taxonomy_object->hierarchical );
            if ( $kind === 'category' && ! $is_hierarchical ) {
                continue;
            }
            if ( $kind === 'tag' && $is_hierarchical ) {
                continue;
            }
            $terms = get_the_terms( $post, $taxonomy );
            if ( ! is_array( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }
                $label = $this->clean_phrase( (string) $term->name );
                if ( $label !== '' && strtolower( $label ) !== 'uncategorized' ) {
                    $names[ strtolower( $label ) ] = $label;
                }
            }
        }
        return array_values( $names );
    }

    private function build_extra_keywords( int $post_id, string $model_name, array $tag_attributes, array $category_attributes, bool $include_tags, bool $include_categories ): array {
        $target = 5 + ( $post_id % 4 ); // 5-8 deterministic.
        $seed   = max( 0, $post_id );

        $name_candidates = $this->patterns_for_seed( self::MODEL_NAME_PATTERNS, $seed, 4 );

        $tag_candidates      = $this->attribute_candidates( $tag_attributes, 'tag_pattern', $seed + 7 );
        $category_candidates = $this->attribute_candidates( $category_attributes, 'category_pattern', $seed + 13 );

        $results = [];

        foreach ( $name_candidates as $pattern ) {
            $keyword = $this->clean_phrase( str_replace( '{model}', $model_name, $pattern ) );
            if ( $keyword !== '' ) {
                $results[] = [ 'keyword' => $keyword, 'source' => 'model_name_pattern' ];
            }
            if ( count( $results ) >= $target ) {
                break;
            }
        }

        $mix_sources = [ $tag_candidates, $category_candidates, $name_candidates ];
        $cursor      = 0;

        while ( count( $results ) < $target && $cursor < 40 ) {
            foreach ( $mix_sources as $index => $source_group ) {
                if ( empty( $source_group ) ) {
                    continue;
                }

                if ( $index === 2 ) {
                    $pattern = $source_group[ ( $cursor + $seed ) % count( $source_group ) ];
                    $keyword = $this->clean_phrase( str_replace( '{model}', $model_name, $pattern ) );
                    $entry   = [ 'keyword' => $keyword, 'source' => 'model_name_pattern' ];
                } else {
                    $entry = $source_group[ ( $cursor + $seed + $index ) % count( $source_group ) ];
                }

                if ( ! empty( $entry['keyword'] ) ) {
                    $results[] = $entry;
                }

                if ( count( $results ) >= $target ) {
                    break 2;
                }
            }
            $cursor++;
        }

        $deduped = [];
        $seen    = [];
        foreach ( $results as $row ) {
            $key = strtolower( trim( (string) ( $row['keyword'] ?? '' ) ) );
            if ( $key === '' || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $deduped[]    = [
                'keyword' => (string) $row['keyword'],
                'source'  => (string) $row['source'],
            ];
            if ( count( $deduped ) >= $target ) {
                break;
            }
        }

        return $deduped;
    }

    private function collect_terms( int $post_id, array $taxonomies ): array {
        $names = [];
        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_the_terms( $post_id, $taxonomy );
            if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }
                $label = $this->clean_phrase( (string) $term->name );
                if ( $label !== '' ) {
                    $names[ strtolower( $label ) ] = $label;
                }
            }
        }
        return array_values( $names );
    }

    private function filter_safe_attributes( array $attributes ): array {
        $safe = [];
        foreach ( $attributes as $attribute ) {
            $attribute = $this->clean_phrase( (string) $attribute );
            $lower     = strtolower( $attribute );
            if ( $attribute === '' ) {
                continue;
            }
            $blocked = false;
            foreach ( self::DISALLOWED_ATTRIBUTES as $needle ) {
                if ( str_contains( $lower, $needle ) ) {
                    $blocked = true;
                    break;
                }
            }
            if ( ! $blocked ) {
                $safe[ $lower ] = $attribute;
            }
        }
        return array_values( $safe );
    }

    private function attribute_candidates( array $attributes, string $source, int $seed ): array {
        if ( empty( $attributes ) ) {
            return [];
        }
        $patterns = $this->patterns_for_seed( self::ATTRIBUTE_PATTERNS, $seed, count( self::ATTRIBUTE_PATTERNS ) );
        $entries  = [];
        foreach ( $attributes as $i => $attribute ) {
            $pattern = $patterns[ $i % count( $patterns ) ];
            $keyword = $this->clean_phrase( str_replace( '{attribute}', $attribute, $pattern ) );
            if ( $keyword !== '' ) {
                $entries[] = [ 'keyword' => $keyword, 'source' => $source ];
            }
        }
        return $entries;
    }



    private function filter_descriptive_attributes( array $attributes, string $model_name, array &$ignored ): array {
        $safe = $this->filter_safe_attributes( $attributes );
        $kept = [];
        foreach ( $safe as $attribute ) {
            if ( $this->is_name_like_attribute( $attribute, $model_name ) ) {
                $ignored[] = $attribute;
                continue;
            }
            $kept[] = $attribute;
        }
        return $kept;
    }

    private function is_name_like_attribute( string $attribute, string $model_name ): bool {
        $attribute_norm = $this->normalize_term( $attribute );
        $model_norm = $this->normalize_term( $model_name );
        if ( $attribute_norm === '' || $model_norm === '' ) {
            return false;
        }

        if ( $attribute_norm === $model_norm ) {
            return true;
        }

        $model_parts = array_values( array_filter( preg_split( '/\s+/', $model_norm ) ?: [] ) );
        if ( in_array( $attribute_norm, $model_parts, true ) ) {
            return true;
        }

        $attribute_parts = array_values( array_filter( preg_split( '/\s+/', $attribute_norm ) ?: [] ) );
        if ( ! empty( $attribute_parts ) && count( array_diff( $attribute_parts, $model_parts ) ) === 0 ) {
            return true;
        }

        $joined = str_replace( ' ', '-', $attribute_norm );
        if ( $joined === sanitize_title( $model_name ) ) {
            return true;
        }

        foreach ( self::DESCRIPTIVE_HINTS as $hint ) {
            if ( str_contains( $attribute_norm, $this->normalize_term( $hint ) ) ) {
                return false;
            }
        }

        if ( count( $attribute_parts ) >= 2 && count( $attribute_parts ) <= 3 && preg_match( '/^[a-z\s-]+$/', $attribute_norm ) === 1 ) {
            $generic_tokens = [ 'webcam', 'cam', 'chat', 'live', 'adult', 'private', 'show', 'model', 'solo' ];
            if ( count( array_intersect( $attribute_parts, $generic_tokens ) ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private function normalize_term( string $value ): string {
        $value = strtolower( $this->clean_phrase( $value ) );
        $value = preg_replace( '/[^a-z0-9\s-]+/', ' ', $value );
        $value = preg_replace( '/[-_]+/', ' ', (string) $value );
        $value = preg_replace( '/\s+/', ' ', (string) $value );
        return trim( (string) $value );
    }

    private function taxonomies_for_model_preview(): array {
        $taxonomies = get_object_taxonomies( 'model', 'names' );
        if ( ! is_array( $taxonomies ) ) {
            return [ 'post_tag', 'models', 'category', 'model_category' ];
        }

        $merged = array_values( array_unique( array_merge( [ 'post_tag', 'models', 'category', 'model_category' ], $taxonomies ) ) );
        return array_values( array_filter( $merged, 'taxonomy_exists' ) );
    }

    private function patterns_for_seed( array $patterns, int $seed, int $count ): array {
        $total = count( $patterns );
        if ( $total === 0 ) {
            return [];
        }
        $rotated = [];
        for ( $i = 0; $i < min( $count, $total ); $i++ ) {
            $rotated[] = $patterns[ ( $seed + $i ) % $total ];
        }
        return $rotated;
    }

    private function clean_phrase( string $value ): string {
        $value = preg_replace( '/\s+/u', ' ', trim( $value ) );
        return is_string( $value ) ? $value : '';
    }
}
