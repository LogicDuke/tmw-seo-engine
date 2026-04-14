<?php
/**
 * TMW SEO Engine — Model Research Provider Contract
 *
 * Shared provider interface for model research implementations. This contract
 * must load before any provider class, regardless of whether the request is a
 * front-end load, wp-admin load, AJAX request, fresh install, or plugin update.
 *
 * @package TMWSEO\Engine\Model
 * @since   5.1.2
 */

namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Every research provider must implement this interface.
 * A provider's lookup() method must be side-effect-free: it collects candidate
 * data and returns it. The pipeline decides whether/how to persist it.
 */
interface ModelResearchProvider {
    /** Unique machine-readable identifier, e.g. 'dataforseo_serp'. */
    public function provider_name(): string;

    /**
     * Look up data for a single model post.
     *
     * @param int    $post_id    WordPress post ID of the model.
     * @param string $model_name Display name / title of the model.
     *
     * @return array{
     *   status: string,
     *   message?: string,
     *   display_name?: string,
     *   aliases?: string[],
     *   bio?: string,
     *   platform_names?: string[],
     *   social_urls?: string[],
     *   field_confidence?: array<string,int>,
     *   research_diagnostics?: array<string,mixed>,
     *   country?: string,
     *   language?: string,
     *   source_urls?: string[],
     *   confidence?: int,
     *   notes?: string,
     * }
     */
    public function lookup( int $post_id, string $model_name ): array;
}
