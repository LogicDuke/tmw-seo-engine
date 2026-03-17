<?php
/**
 * TMW SEO Engine — Keyword Idea Aggregator
 *
 * Fans out to all registered KeywordIdeaProviderInterface implementations,
 * merges the results, and returns a unified item list. DataForSEO remains
 * supported and is included as the primary provider; Google Keyword Planner
 * and Google Trends are additive when configured and enabled.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MERGE RULES:
 *  - Results are de-duplicated by normalized keyword.
 *  - First provider to surface a keyword "wins" the volume/cpc provenance
 *    slot, UNLESS a higher-priority provider also has the keyword (DataForSEO
 *    > Google KP > Google Trends for volume authority).
 *  - Trend metadata (_tmw_trend_score, _tmw_trend_direction, etc.) is always
 *    applied from Google Trends if available, regardless of which provider
 *    first surfaced the keyword.
 *  - Items from unavailable providers are silently omitted — no error
 *    propagation to the caller.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * USAGE (in KeywordEngine::fetch_seed_relationships):
 *   $agg = new KeywordIdeaAggregator();
 *   $result = $agg->fetch( $seed, $limit );
 *   // $result['ok']    bool
 *   // $result['items'] array of keyword items
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordIdeaAggregator {

    /**
     * Volume-authority order: first provider in this list wins volume/cpc provenance.
     * @var string[]
     */
    private const VOLUME_AUTHORITY_ORDER = [
        'dataforseo',
        'google_keyword_planner',
        'google_trends',
        'google_autosuggest',
    ];

    /** @var KeywordIdeaProviderInterface[] */
    private array $providers;

    public function __construct() {
        // Providers are registered here in priority order.
        // The aggregator always loads DataForSEO first (established primary provider).
        // Google KP and Trends are additive and gated by their own is_available() checks.
        // Google Autosuggest is free and always available — replaces the removed
        // discover_from_seeds() double-dip path.
        $this->providers = [
            new DataForSEOKeywordIdeaProvider(),
            new GoogleKeywordPlannerIdeaProvider(),
            new GoogleTrendsIdeaProvider(),
            new GoogleAutosuggestIdeaProvider(),
        ];
    }

    /**
     * Fetch keyword ideas from all available providers and merge.
     *
     * @param string $seed   Root keyword to expand.
     * @param int    $limit  Per-provider result limit.
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetch( string $seed, int $limit ): array {
        $merged         = [];  // normalized_keyword => item
        $trend_overlay  = [];  // normalized_keyword => trend meta from Google Trends
        $had_any_ok     = false;
        $last_error     = '';
        $active_providers = [];

        foreach ( $this->providers as $provider ) {
            if ( ! $provider->is_available() ) { continue; }
            $active_providers[] = $provider->provider_name();

            $result = $provider->fetch( $seed, $limit );

            if ( empty( $result['ok'] ) ) {
                $last_error = (string) ( $result['error'] ?? '' );
                Logs::warn( 'keyword-aggregator', 'Provider fetch failed', [
                    'provider' => $provider->provider_name(),
                    'seed'     => $seed,
                    'error'    => $last_error,
                ] );
                continue;
            }

            $had_any_ok = true;

            foreach ( (array) ( $result['items'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $kw   = (string) ( $item['keyword'] ?? '' );
                $nk   = KeywordValidator::normalize( $kw );
                if ( $nk === '' ) { continue; }

                $provider_name = $provider->provider_name();

                // Collect trend metadata from Trends provider regardless of merge order.
                if ( $provider_name === 'google_trends' && isset( $item['_tmw_trend_score'] ) ) {
                    $trend_overlay[ $nk ] = [
                        '_tmw_trend_score'       => (int) ( $item['_tmw_trend_score'] ?? 0 ),
                        '_tmw_trend_direction'   => (string) ( $item['_tmw_trend_direction'] ?? 'flat' ),
                        '_tmw_seasonality_index' => (float) ( $item['_tmw_seasonality_index'] ?? 0.0 ),
                        '_tmw_volume_source'     => 'google_trends',
                    ];
                }

                if ( isset( $merged[ $nk ] ) ) {
                    // Already have this keyword — merge volume/cpc if higher authority.
                    $existing_source   = (string) ( $merged[ $nk ]['_tmw_volume_source'] ?? 'unknown' );
                    $existing_priority = (int) array_search( $existing_source, self::VOLUME_AUTHORITY_ORDER, true );
                    $new_priority      = (int) array_search( $provider_name, self::VOLUME_AUTHORITY_ORDER, true );

                    if ( $new_priority < $existing_priority ) {
                        // New provider has higher authority — overwrite volume/cpc provenance.
                        $merged[ $nk ]['keyword_info']       = $item['keyword_info'] ?? $merged[ $nk ]['keyword_info'];
                        $merged[ $nk ]['_tmw_volume_source'] = $provider_name;
                        $merged[ $nk ]['_tmw_cpc_source']    = $provider_name;
                    }
                } else {
                    // New keyword — add it.
                    $item['_tmw_volume_source']    = $item['_tmw_volume_source'] ?? $provider_name;
                    $item['_tmw_cpc_source']       = $item['_tmw_cpc_source']    ?? $provider_name;
                    $item['_tmw_trend_score']      = 0;
                    $item['_tmw_trend_direction']  = 'unknown';
                    $item['_tmw_seasonality_index']= 0.0;
                    $merged[ $nk ] = $item;
                }
            }
        }

        // Apply trend overlay from Google Trends to all merged items.
        foreach ( $trend_overlay as $nk => $trend_meta ) {
            if ( isset( $merged[ $nk ] ) ) {
                $merged[ $nk ]['_tmw_trend_score']       = $trend_meta['_tmw_trend_score'];
                $merged[ $nk ]['_tmw_trend_direction']   = $trend_meta['_tmw_trend_direction'];
                $merged[ $nk ]['_tmw_seasonality_index'] = $trend_meta['_tmw_seasonality_index'];
            }
        }

        if ( ! $had_any_ok ) {
            return [
                'ok'    => false,
                'error' => $last_error ?: 'all_providers_failed',
            ];
        }

        Logs::info( 'keyword-aggregator', 'Aggregation complete', [
            'seed'             => $seed,
            'active_providers' => $active_providers,
            'total_items'      => count( $merged ),
            'trend_overlays'   => count( $trend_overlay ),
        ] );

        return [ 'ok' => true, 'items' => array_values( $merged ) ];
    }
}
