<?php
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ModelKeywordSuggestionGenerator {

    private const RAW_DISALLOWED_ATTRIBUTES = [
        'teen','young','underage','schoolgirl','school','student','child','kids',
        'leaked','leaks','torrent','download','reddit','discord','telegram','onlyfans leak',
        'webcam driver','security camera','surveillance','zoom','teams','logitech',
        'video','videos','landscape','bed','room','toy','sextoy','dildo','vibrator',
        'pussy','ass','blowjob','deepthroat','suck','gag','anal','naked','nude','porn','cam porn',
        'live sex','sex','fuck','fingering','masturbation','missionary','moaning','orgasm','cum','lick',
        'dirty','nasty','slutty','wet','remote toy','machine','xxx','hardcore',
        'white','girl','hot','horny','erotic','cute','babe','shaved','glamour',
        'dance','teasing','bald','eye contact','high heels','stockings','long nails',
        'above average','fake tits','normal tits','tiny tits',
    ];

    private const MODEL_NAME_PATTERNS = [
        '{model} live cam girl','{model} webcam chat','{model} webcam chat girl',
        '{model} live webcam model','{model} live cam chat','{model} cam profile',
        '{model} cam model',
    ];



    private const ATTRIBUTE_NORMALIZATION_MAP = [
        'latina cam girls'   => 'latina',
        'amateur cam girls'  => 'amateur',
        'blonde cam girls'   => 'blonde',
        'big tits cam girls' => 'big tits',
        'milf cam girls'     => 'milf',
        'live cam models'    => 'live cam model',
        'brown hair'         => 'brunette',
        'blonde hair'        => 'blonde',
        'tattoo'             => 'tattooed',
        'tattoos'            => 'tattooed',
        'sologirl'           => 'solo',
        'latin'              => 'latina',
        'natural tits'       => 'natural',
    ];

    private const FINAL_DISALLOWED_KEYWORD_TERMS = [
        'teen','young','underage','schoolgirl','school','student','child','kids',
        'leaked','leaks','torrent','download','reddit','discord','telegram','onlyfans leak',
        'webcam driver','security camera','surveillance','zoom','teams','logitech',
        'video','videos','landscape','bed','room','toy','sextoy','dildo','vibrator',
        'pussy','ass','blowjob','deepthroat','suck','gag','anal','naked','nude','porn','cam porn',
        'live sex','sex','fuck','fingering','masturbation','missionary','moaning','orgasm','cum','lick',
        'dirty','nasty','slutty','wet','remote toy','machine','xxx','hardcore',
    ];

    private const APPROVED_ATTRIBUTES = [
        'amateur','brunette','blonde','black hair','latina','asian','ebony','mature','curvy','petite',
        'tattooed','lingerie','solo','sensual','natural','big tits','milf','athletic','skinny','live cam model','cam girl',
    ];

    private const ATTRIBUTE_PATTERNS = [
        '{attribute} webcam chat model','{attribute} live cam girl',
        '{attribute} adult webcam model','{attribute} cam profile','{attribute} live cam chat',
    ];
    private const ATTRIBUTE_SELECTION_PRIORITY = [
        'latina','brunette','blonde','amateur','tattooed','lingerie','solo','natural',
        'big tits','milf','athletic','skinny','sensual','black hair',
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

        $selection = $this->build_extra_keywords( (int) $post->ID, $model_name, $tag_attributes, $category_attributes, $include_tags, $include_categories );
        $extra = $selection['keywords'];

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
            'attribute_candidates_count' => (int) ( $selection['attribute_candidates_count'] ?? 0 ),
            'selected_attribute_count' => (int) ( $selection['selected_attribute_count'] ?? 0 ),
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
        $base_target = 5 + ( $post_id % 4 ); // 5-8 deterministic.
        $has_attributes = ! empty( $tag_attributes ) || ! empty( $category_attributes );
        $target = $has_attributes ? max( 7, $base_target ) : $base_target;
        $seed   = max( 0, $post_id );

        $model_name_candidates = [];
        foreach ( $this->patterns_for_seed( self::MODEL_NAME_PATTERNS, $seed, count( self::MODEL_NAME_PATTERNS ) ) as $pattern ) {
            $keyword = $this->clean_phrase( str_replace( '{model}', $model_name, $pattern ) );
            if ( $this->is_natural_keyword( $keyword ) ) {
                $model_name_candidates[] = [ 'keyword' => $keyword, 'source' => 'model_name_pattern' ];
            }
        }

        $attribute_candidates = array_merge(
            $this->attribute_candidates( $tag_attributes, 'tag_pattern', $seed + 7 ),
            $this->attribute_candidates( $category_attributes, 'category_pattern', $seed + 13 )
        );

        return $this->select_best_keywords( $model_name_candidates, $attribute_candidates, $model_name, $target );
    }

    private function select_best_keywords( array $model_name_candidates, array $attribute_candidates, string $model_name, int $target ): array {
        $candidate_pool = array_merge( $model_name_candidates, $attribute_candidates );
        $scored = [];
        $seen = [];
        foreach ( $candidate_pool as $row ) {
            $keyword = $this->clean_phrase( (string) ( $row['keyword'] ?? '' ) );
            $source = (string) ( $row['source'] ?? '' );
            $normalized = $this->normalize_term( $keyword );
            if ( $keyword === '' || $normalized === '' || isset( $seen[ $normalized ] ) || ! $this->is_natural_keyword( $keyword ) ) {
                continue;
            }
            if ( ! $this->is_safe_model_intent_keyword( $keyword ) ) {
                continue;
            }
            $seen[ $normalized ] = true;
            $score = $this->score_keyword_candidate( $keyword, $source, $model_name );
            if ( $score <= 0 ) {
                continue;
            }
            $scored[] = [
                'keyword' => $keyword,
                'source' => $source,
                'score' => $score,
                'ending' => $this->keyword_ending( $keyword ),
                'attribute' => (string) ( $row['attribute'] ?? '' ),
            ];
        }

        usort( $scored, static function ( array $a, array $b ): int {
            if ( $a['score'] === $b['score'] ) {
                return strcmp( (string) $a['keyword'], (string) $b['keyword'] );
            }
            return $b['score'] <=> $a['score'];
        } );

        $name_entries = array_values( array_filter( $scored, static fn( array $entry ): bool => (string) ( $entry['source'] ?? '' ) === 'model_name_pattern' ) );
        $attribute_entries = array_values( array_filter( $scored, static fn( array $entry ): bool => (string) ( $entry['source'] ?? '' ) !== 'model_name_pattern' ) );

        $attribute_candidates_count = count( $attribute_entries );
        $name_target = 3;
        $attribute_target = 0;
        if ( $attribute_candidates_count > 0 ) {
            $attribute_target = min( 4, max( 2, $target - $name_target ) );
            $attribute_target = min( $attribute_target, $attribute_candidates_count );
            if ( $attribute_target < 2 ) {
                $name_target = max( 0, $target - $attribute_target );
            }
        }

        $selected = [];
        $endings = [];

        $this->append_entries_by_count( $selected, $endings, $name_entries, $name_target );
        $this->append_diverse_attribute_entries( $selected, $endings, $attribute_entries, $attribute_target );

        if ( $attribute_candidates_count > 0 && $this->count_selected_attributes( $selected ) === 0 ) {
            $this->append_diverse_attribute_entries( $selected, $endings, $attribute_entries, 1 );
        }

        if ( count( $selected ) < $target ) {
            $this->append_diverse_attribute_entries( $selected, $endings, $attribute_entries, $target - count( $selected ) );
        }
        if ( count( $selected ) < $target ) {
            $this->append_entries_by_count( $selected, $endings, $name_entries, $target - count( $selected ) );
        }

        $selected = array_slice( $selected, 0, $target );
        $selected_attribute_count = count( array_filter( $selected, static fn( array $entry ): bool => (string) ( $entry['source'] ?? '' ) !== 'model_name_pattern' ) );

        return [
            'keywords' => $selected,
            'attribute_candidates_count' => $attribute_candidates_count,
            'selected_attribute_count' => $selected_attribute_count,
        ];
    }

    private function append_diverse_attribute_entries( array &$selected, array &$endings, array $entries, int $count ): void {
        if ( $count <= 0 || empty( $entries ) ) {
            return;
        }

        $grouped = [];
        foreach ( $entries as $entry ) {
            $attribute = $this->normalize_term( (string) ( $entry['attribute'] ?? '' ) );
            if ( $attribute === '' ) {
                $attribute = '__no_attribute__';
            }
            $grouped[ $attribute ][] = $entry;
        }

        $ordered_attributes = array_keys( $grouped );
        usort( $ordered_attributes, function ( string $left, string $right ): int {
            $left_rank = $this->attribute_priority_rank( $left );
            $right_rank = $this->attribute_priority_rank( $right );
            if ( $left_rank === $right_rank ) {
                return strcmp( $left, $right );
            }
            return $left_rank <=> $right_rank;
        } );

        $first_pass = [];
        foreach ( $ordered_attributes as $attribute ) {
            $first_pass[] = $grouped[ $attribute ][0];
        }
        $initial_attribute_count = $this->count_selected_attributes( $selected );
        $this->append_entries_by_count( $selected, $endings, $first_pass, $count );

        $added = $this->count_selected_attributes( $selected ) - $initial_attribute_count;
        if ( $added >= $count ) {
            return;
        }

        $overflow = [];
        foreach ( $ordered_attributes as $attribute ) {
            if ( count( $grouped[ $attribute ] ) <= 1 ) {
                continue;
            }
            foreach ( array_slice( $grouped[ $attribute ], 1 ) as $entry ) {
                $overflow[] = $entry;
            }
        }
        $this->append_entries_by_count( $selected, $endings, $overflow, $count - $added );
    }

    private function attribute_priority_rank( string $attribute ): int {
        $rank = array_search( $attribute, self::ATTRIBUTE_SELECTION_PRIORITY, true );
        if ( is_int( $rank ) ) {
            return $rank;
        }
        return 999;
    }

    private function append_entries_by_count( array &$selected, array &$endings, array $entries, int $count ): void {
        if ( $count <= 0 ) {
            return;
        }

        $added = 0;
        foreach ( $entries as $entry ) {
            if ( $added >= $count ) {
                break;
            }

            $keyword = (string) ( $entry['keyword'] ?? '' );
            if ( $keyword === '' || $this->keyword_already_selected( $selected, $keyword ) ) {
                continue;
            }

            $ending = (string) ( $entry['ending'] ?? '' );
            if ( $ending !== '' && ( $endings[ $ending ] ?? 0 ) >= 2 ) {
                continue;
            }

            $selected[] = [
                'keyword' => $keyword,
                'source'  => (string) ( $entry['source'] ?? '' ),
            ];
            $endings[ $ending ] = ( $endings[ $ending ] ?? 0 ) + 1;
            $added++;
        }
    }

    private function count_selected_attributes( array $selected ): int {
        return count( array_filter( $selected, static fn( array $entry ): bool => (string) ( $entry['source'] ?? '' ) !== 'model_name_pattern' ) );
    }

    private function keyword_already_selected( array $selected, string $keyword ): bool {
        $needle = strtolower( $keyword );
        foreach ( $selected as $row ) {
            if ( strtolower( (string) ( $row['keyword'] ?? '' ) ) === $needle ) {
                return true;
            }
        }
        return false;
    }


    private function is_safe_model_intent_keyword( string $keyword ): bool {
        if ( PageTypeKeywordFilter::is_unsafe( $keyword ) ) {
            return false;
        }

        return ! empty( PageTypeKeywordFilter::filter_for_model_page( [ $keyword ] ) );
    }

    private function score_keyword_candidate( string $keyword, string $source, string $model_name ): int {
        $normalized = $this->normalize_term( $keyword );
        $words = array_values( array_filter( preg_split( '/\s+/', $normalized ) ?: [] ) );
        $word_count = count( $words );
        $score = 10;
        $commercial_phrases = [ 'webcam chat', 'live cam', 'cam model', 'cam profile', 'live cam chat', 'live webcam model' ];
        $attribute_terms = [ 'latina', 'brunette', 'blonde', 'amateur', 'tattooed', 'lingerie', 'solo', 'milf', 'big tits', 'natural', 'athletic', 'skinny' ];
        $body_terms = [ 'big tits', 'skinny', 'athletic', 'curvy', 'petite', 'milf', 'mature' ];

        foreach ( $commercial_phrases as $phrase ) {
            if ( str_contains( $normalized, $phrase ) ) {
                $score += 8;
                break;
            }
        }

        if ( str_contains( $normalized, $this->normalize_term( $model_name ) ) ) {
            $score += 5;
        }
        foreach ( $attribute_terms as $term ) {
            if ( str_contains( $normalized, $term ) ) {
                $score += 4;
                break;
            }
        }

        $ideal_min = $source === 'model_name_pattern' ? 3 : 3;
        $ideal_max = $source === 'model_name_pattern' ? 7 : 6;
        if ( $word_count >= $ideal_min && $word_count <= $ideal_max ) {
            $score += 4;
        } elseif ( $word_count < $ideal_min || $word_count > $ideal_max + 1 ) {
            $score -= 8;
        }

        if ( preg_match( '/\b(webcam|cam|model|live)\s+\1\b/u', $normalized ) === 1 ) {
            $score -= 30;
        }

        $body_hits = 0;
        foreach ( $body_terms as $term ) {
            if ( str_contains( $normalized, $term ) ) {
                $body_hits++;
            }
        }
        if ( $body_hits > 1 ) {
            $score -= 20;
        }

        return $score;
    }

    private function keyword_ending( string $keyword ): string {
        $normalized = $this->normalize_term( $keyword );
        $priority_endings = [ 'webcam chat model', 'adult webcam model', 'cam profile', 'live cam chat' ];
        foreach ( $priority_endings as $ending ) {
            if ( str_ends_with( $normalized, $ending ) ) {
                return $ending;
            }
        }
        return implode( ' ', array_slice( array_values( array_filter( preg_split( '/\s+/', $normalized ) ?: [] ) ), -3 ) );
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

    private function filter_safe_attributes( array $attributes, array &$ignored = [] ): array {
        $safe = [];
        foreach ( $attributes as $attribute ) {
            $attribute = $this->normalize_attribute_label( $this->clean_phrase( (string) $attribute ) );
            $lower     = strtolower( $attribute );
            if ( $attribute === '' ) {
                continue;
            }
            $blocked = false;
            foreach ( self::RAW_DISALLOWED_ATTRIBUTES as $needle ) {
                if ( str_contains( $lower, $needle ) ) {
                    $blocked = true;
                    $ignored[] = $attribute;
                    break;
                }
            }
            if ( ! $blocked ) {
                $safe[ $lower ] = $attribute;
            }
        }
        return array_values( $safe );
    }


    private function normalize_attribute_label( string $attribute ): string {
        $normalized = $this->normalize_term( $attribute );
        if ( $normalized === '' ) {
            return '';
        }

        if ( preg_match( '/^(.+?)\s+cam\s+girls?$/', $normalized, $matches ) === 1 ) {
            return trim( (string) ( $matches[1] ?? '' ) );
        }

        if ( isset( self::ATTRIBUTE_NORMALIZATION_MAP[ $normalized ] ) ) {
            return self::ATTRIBUTE_NORMALIZATION_MAP[ $normalized ];
        }

        return $normalized;
    }

    private function attribute_candidates( array $attributes, string $source, int $seed ): array {
        if ( empty( $attributes ) ) {
            return [];
        }
        $patterns = $this->patterns_for_seed( self::ATTRIBUTE_PATTERNS, $seed, count( self::ATTRIBUTE_PATTERNS ) );
        $entries  = [];
        foreach ( $attributes as $attribute ) {
            foreach ( $this->patterns_for_attribute( $attribute, $patterns ) as $pattern ) {
                $keyword = $this->clean_phrase( str_replace( '{attribute}', $attribute, $pattern ) );
                if ( $this->is_natural_keyword( $keyword ) ) {
                    $entries[] = [ 'keyword' => $keyword, 'source' => $source, 'attribute' => $attribute ];
                }
            }
        }
        return $entries;
    }

    private function patterns_for_attribute( string $attribute, array $default_patterns ): array {
        $normalized = $this->normalize_term( $attribute );
        if ( $normalized === 'cam girl' ) {
            return [
                '{attribute} webcam chat',
            ];
        }
        if ( $normalized === 'live cam model' ) {
            return [
                '{attribute} profile',
                '{attribute} webcam chat',
            ];
        }

        return $default_patterns;
    }

    private function is_natural_keyword( string $keyword ): bool {
        $normalized = $this->normalize_term( $keyword );
        if ( $normalized === '' ) {
            return false;
        }
        foreach ( self::FINAL_DISALLOWED_KEYWORD_TERMS as $blocked ) {
            if ( str_contains( $normalized, $blocked ) ) {
                return false;
            }
        }
        if ( str_contains( $normalized, 'live cam model live cam chat' ) || str_contains( $normalized, 'cam girl live cam girl' ) || str_contains( $normalized, 'webcam webcam chat' ) ) {
            return false;
        }
        if ( preg_match( '/\b(\w+)\s+\1\b/u', $normalized ) === 1 ) {
            return false;
        }
        return true;
    }



    private function filter_descriptive_attributes( array $attributes, string $model_name, array &$ignored ): array {
        $safe = $this->filter_safe_attributes( $attributes, $ignored );
        $kept = [];
        foreach ( $safe as $attribute ) {
            if ( in_array( $attribute, self::APPROVED_ATTRIBUTES, true ) ) {
                $kept[] = $attribute;
                continue;
            }

            if ( $this->is_name_like_attribute( $attribute, $model_name ) ) {
                $ignored[] = $attribute;
                continue;
            }

            $ignored[] = $attribute;
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
