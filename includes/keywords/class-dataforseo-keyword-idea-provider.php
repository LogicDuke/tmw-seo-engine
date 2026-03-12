<?php
/**
 * TMW SEO Engine — DataForSEO Keyword Idea Provider
 *
 * Wraps the existing DataForSEO service as a KeywordIdeaProviderInterface
 * implementation. Fetches keyword_suggestions + related_keywords and merges them.
 * This is the same logic that was previously inlined in KeywordEngine::fetch_seed_relationships().
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DataForSEOKeywordIdeaProvider implements KeywordIdeaProviderInterface {

    public function provider_name(): string {
        return 'dataforseo';
    }

    public function is_available(): bool {
        return DataForSEO::is_configured() && ! DataForSEO::is_over_budget();
    }

    /**
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetch( string $seed, int $limit ): array {
        $suggestions_res = DataForSEO::keyword_suggestions( $seed, $limit );
        $related_res     = DataForSEO::related_keywords( $seed, 1, $limit );

        if ( empty( $suggestions_res['ok'] ) && empty( $related_res['ok'] ) ) {
            return [
                'ok'    => false,
                'error' => (string) ( $suggestions_res['error'] ?? $related_res['error'] ?? 'dataforseo_keyword_discovery_failed' ),
            ];
        }

        $merged = [];

        foreach ( [
            [ 'type' => 'suggestion', 'items' => (array) ( $suggestions_res['items'] ?? [] ) ],
            [ 'type' => 'related',    'items' => (array) ( $related_res['items']     ?? [] ) ],
        ] as $source ) {
            foreach ( $source['items'] as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $kw = (string) ( $item['keyword'] ?? '' );
                if ( $kw === '' ) { continue; }
                $item['_tmw_relationship_type'] = $source['type'];
                // Provenance
                $item['_tmw_volume_source'] = 'dataforseo';
                $item['_tmw_cpc_source']    = 'dataforseo';
                $normalized = KeywordValidator::normalize( $kw );
                if ( $normalized !== '' && ! isset( $merged[ $normalized ] ) ) {
                    $merged[ $normalized ] = $item;
                }
            }
        }

        return [ 'ok' => true, 'items' => array_values( $merged ) ];
    }
}
