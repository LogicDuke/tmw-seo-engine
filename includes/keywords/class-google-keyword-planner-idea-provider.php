<?php
/**
 * TMW SEO Engine — Google Keyword Planner Idea Provider
 *
 * Implements KeywordIdeaProviderInterface using GoogleAdsKeywordPlannerApi.
 * Wraps the live API call and returns shaped items for the aggregator.
 *
 * Feature gate: only active when 'google_ads_enabled' = 1 and all
 * five credential fields are set. Silently skipped otherwise.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Integrations\GoogleAdsKeywordPlannerApi;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleKeywordPlannerIdeaProvider implements KeywordIdeaProviderInterface {

    public function provider_name(): string {
        return 'google_keyword_planner';
    }

    public function is_available(): bool {
        return GoogleAdsKeywordPlannerApi::is_configured();
    }

    /**
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetch( string $seed, int $limit ): array {
        return GoogleAdsKeywordPlannerApi::keyword_ideas( $seed, $limit );
    }
}
