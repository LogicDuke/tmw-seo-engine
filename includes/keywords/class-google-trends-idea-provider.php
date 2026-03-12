<?php
/**
 * TMW SEO Engine — Google Trends Keyword Idea Provider
 *
 * Implements KeywordIdeaProviderInterface using GoogleTrends service.
 * Returns rising and related query items shaped for the aggregator pipeline.
 *
 * Feature gate: only active when 'google_trends_enabled' = 1 in Settings.
 * Disabled providers are silently skipped by KeywordIdeaAggregator.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Services\GoogleTrends;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleTrendsIdeaProvider implements KeywordIdeaProviderInterface {

    public function provider_name(): string {
        return 'google_trends';
    }

    public function is_available(): bool {
        return GoogleTrends::is_enabled();
    }

    /**
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetch( string $seed, int $limit ): array {
        return GoogleTrends::get_related_queries( $seed, $limit );
    }
}
