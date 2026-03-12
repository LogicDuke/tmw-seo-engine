<?php
/**
 * TMW SEO Engine — Keyword Idea Provider Interface
 *
 * Every keyword idea source (DataForSEO, Google Keyword Planner, Google Trends)
 * must implement this interface. The aggregator (KeywordIdeaAggregator) fans out
 * to all registered providers and merges results before handing them to the engine.
 *
 * Contract:
 * - fetch() must return an array shaped as:
 *     [
 *       'ok'    => bool,
 *       'items' => array<int, array{keyword:string, _tmw_relationship_type:string, keyword_info?:array}>,
 *       'error' => string, // only present when ok===false
 *     ]
 * - Implementations must NOT throw. They catch their own exceptions and return ok=>false.
 * - 'keyword_info' sub-array may include:
 *     search_volume (int), cpc (float), competition (float), keyword_difficulty (float)
 * - '_tmw_relationship_type' must be one of: suggestion | related | trend_rising | trend_related | trend_entity
 * - Implementations should cache aggressively to avoid redundant API calls.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface KeywordIdeaProviderInterface {

    /**
     * Human-readable provider name — used in source provenance strings.
     */
    public function provider_name(): string;

    /**
     * Whether this provider is configured and available right now.
     * The aggregator silently skips providers that return false here.
     */
    public function is_available(): bool;

    /**
     * Fetch keyword ideas for $seed.
     *
     * @param string $seed     The root keyword to expand.
     * @param int    $limit    Maximum items to return per provider.
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetch( string $seed, int $limit ): array;
}
