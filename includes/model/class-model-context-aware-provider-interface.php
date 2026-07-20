<?php
/**
 * TMW SEO Engine — Context-Aware Provider Interface (v4.6.9)
 *
 * Optional interface for research providers that need to consume artifacts
 * (e.g., discovered handles) from providers that ran earlier in the same
 * pipeline execution.
 *
 * The pipeline calls set_prior_results() immediately before lookup() when
 * a provider implements this interface. The provider receives a snapshot of
 * every result collected so far in the current run, keyed by provider_name.
 *
 * Design constraints:
 *  - Backward-compatible: providers that do NOT implement this interface
 *    continue to receive only the standard lookup(post_id, model_name) call.
 *  - Side-effect-free: set_prior_results() must only cache data for the
 *    forthcoming lookup() call — it must not write to the database.
 *  - Trust boundary unchanged: any handles consumed from prior results must
 *    still pass through parse_url_for_platform_structured() before being
 *    admitted into platform_names or platform_candidates.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.9
 */

namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Allows a provider to receive accumulated results from earlier providers
 * in the same pipeline run before its own lookup() is called.
 *
 * Usage by a provider:
 *   class MyProvider implements ModelResearchProvider, ModelContextAwareProvider {
 *       private array $prior_results = [];
 *       public function set_prior_results( array $prior_results ): void {
 *           $this->prior_results = $prior_results;
 *       }
 *   }
 */
interface ModelContextAwareProvider {

    /**
     * Receive a snapshot of results accumulated from earlier providers.
     *
     * Called by ModelResearchPipeline::run() immediately before lookup().
     * The array is keyed by provider_name and contains the full result
     * arrays returned by each prior provider.
     *
     * Implementations must be idempotent and must not write to the database.
     *
     * @param array<string,array> $prior_results
     *        Map of provider_name => lookup() result for each provider that
     *        has already completed in the current pipeline run.
     */
    public function set_prior_results( array $prior_results ): void;
}
