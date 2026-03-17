<?php
/**
 * TMW SEO Engine — Google Autosuggest Keyword Idea Provider
 *
 * Free keyword discovery source. Wraps Google's Autosuggest API
 * as a KeywordIdeaProviderInterface so it participates in the
 * multi-provider aggregator alongside DataForSEO and Google Trends.
 *
 * This replaces the previous inline autosuggest logic that lived in
 * KeywordDiscoveryService::fetch_google_autosuggest() and was called
 * via the now-removed discover_from_seeds() double-dip path.
 *
 * Cost: FREE — no API key required.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.5.0
 */

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleAutosuggestIdeaProvider implements KeywordIdeaProviderInterface {

    public function provider_name(): string {
        return 'google_autosuggest';
    }

    /**
     * Always available — no credentials or budget needed.
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Fetch keyword ideas from Google Autosuggest (free).
     *
     * @param string $seed  The root keyword to expand.
     * @param int    $limit Maximum items (autosuggest returns ~10 per query).
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetch( string $seed, int $limit ): array {
        $url = add_query_arg( [
            'client' => 'firefox',
            'q'      => $seed,
        ], 'https://suggestqueries.google.com/complete/search' );

        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return [ 'ok' => false, 'error' => 'http_' . $status ];
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
            return [ 'ok' => true, 'items' => [] ];
        }

        $items = [];
        foreach ( $body[1] as $suggestion ) {
            $kw = KeywordValidator::normalize( (string) $suggestion );
            if ( $kw === '' || $kw === KeywordValidator::normalize( $seed ) ) {
                continue;
            }

            $items[] = [
                'keyword'                  => $kw,
                '_tmw_relationship_type'   => 'suggestion',
                '_tmw_volume_source'       => 'google_autosuggest',
                '_tmw_cpc_source'          => 'google_autosuggest',
                'keyword_info'             => [
                    'search_volume'      => null,
                    'cpc'                => null,
                    'competition'        => null,
                    'keyword_difficulty' => null,
                ],
            ];

            if ( count( $items ) >= $limit ) {
                break;
            }
        }

        return [ 'ok' => true, 'items' => $items ];
    }
}
