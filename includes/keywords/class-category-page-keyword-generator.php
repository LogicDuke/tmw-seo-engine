<?php
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds category-page keyword packs from labels only.
 *
 * The generator never mutates the source term, slug, or label. It produces a
 * small archive/browse-intent pack and does not use model names or video titles.
 */
class CategoryPageKeywordGenerator {

    /**
     * @return array{primary:string,additional:string[],longtail:string[],sources:array}
     */
    public static function generate( string $category_label ): array {
        $label     = self::clean_label( $category_label );
        $attribute = self::extract_attribute( $label );

        $primary = $attribute !== '' ? $attribute . ' webcam models' : 'webcam model categories';
        $additional = $attribute !== ''
            ? [
                $attribute . ' live cam',
                $attribute . ' cam girls',
                'best ' . $attribute . ' webcam models',
                'live ' . $attribute . ' chat',
            ]
            : [
                'live cam categories',
                'webcam model archive',
                'browse webcam models',
                'best live cam topics',
            ];

        $filter = PageTypeKeywordFilter::class;
        $primary_candidates = $filter::filter_for_category_page( [ $primary ] );
        $primary = $primary_candidates[0] ?? 'webcam model categories';
        $additional = $filter::filter_for_category_page( $additional );

        return [
            'primary'    => $primary,
            'additional' => array_slice( $additional, 0, 4 ),
            'longtail'   => [],
            'sources'    => [
                'category_label' => $label,
                'page_type'      => 'category',
            ],
        ];
    }

    private static function clean_label( string $label ): string {
        $label = wp_strip_all_tags( $label );
        $label = strtolower( $label );
        $label = str_replace( '&', ' ', $label );
        $label = preg_replace( '/[^a-z0-9\s-]+/u', ' ', $label );
        $label = preg_replace( '/[-_]+/u', ' ', (string) $label );
        $label = preg_replace( '/\s+/u', ' ', (string) $label );
        return trim( (string) $label );
    }

    private static function extract_attribute( string $label ): string {
        if ( $label === '' || PageTypeKeywordFilter::is_unsafe( $label ) ) {
            return '';
        }

        $tokens = array_values( array_filter( preg_split( '/\s+/u', $label ) ?: [] ) );
        $stop   = [ 'cam', 'cams', 'webcam', 'webcams', 'girl', 'girls', 'model', 'models', 'live', 'chat', 'category', 'categories', 'archive', 'topic', 'topics', 'and' ];
        $kept   = [];
        foreach ( $tokens as $token ) {
            if ( in_array( $token, $stop, true ) ) {
                continue;
            }
            $kept[] = $token;
            if ( count( $kept ) >= 3 ) {
                break;
            }
        }

        return trim( implode( ' ', $kept ) );
    }
}
