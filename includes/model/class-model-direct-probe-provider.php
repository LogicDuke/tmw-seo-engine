<?php
/**
 * TMW SEO Engine — Direct Probe Research Provider (v4.6.8)
 *
 * Complements the DataForSEO SERP provider with direct HTTP-based platform
 * profile recall. While the SERP provider excels at broad discovery (social
 * URLs, hubs, snippets, evidence), this provider focuses on recovering
 * specific platform profile candidates that SERP queries fail to surface
 * because the relevant pages are not indexed or not ranked for the model name.
 *
 * Architecture:
 *   - Implements ModelResearchProvider; registered via tmwseo_research_providers.
 *   - Runs AFTER the DataForSEO SERP provider (filter priority 20 vs. 10).
 *   - Derives handle seeds purely from the model display name — no SERP
 *     pass-one results available at this point.
 *   - Delegates all HTTP probing to ModelPlatformProbe (v4.6.7 conservative).
 *   - Returns only platform_names and platform_candidates; leaves bio, social,
 *     country, language, and source_urls blank (SERP provider handles those).
 *
 * Trust guarantees (identical to SERP provider):
 *   - platform_names only from successful parse_url_for_platform_structured().
 *   - platform_candidates is an audit trail; not auto-applied to live fields.
 *   - No manual usernames or verified data is overwritten.
 *   - Safe Mode suppresses all probes.
 *
 * Sync-budget note:
 *   This provider inherits ModelPlatformProbe's budget:
 *   6 × 3 s HEAD + 2 × 4 s GET = ~26 s worst-case.
 *   Combined with the SERP provider's ~40–50 s, total may approach the
 *   Cloudflare 100 s proxy limit on slow edges. Reduce ModelPlatformProbe
 *   MAX_PROBES to 3 if timeouts occur.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.8
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Platform\PlatformRegistry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Direct-probe research provider for platform profile recall.
 *
 * Generates handle seeds from the model display name and delegates to
 * ModelPlatformProbe for bounded HTTP verification. Results flow through
 * PlatformProfiles::parse_url_for_platform_structured() before being accepted
 * into platform_candidates — the same trust gate used by the SERP provider.
 */
class ModelDirectProbeProvider implements ModelResearchProvider {

    /**
     * {@inheritdoc}
     */
    public function provider_name(): string {
        return 'direct_probe';
    }

    /**
     * {@inheritdoc}
     *
     * Builds name-derived handle seeds, runs the bounded HTTP probe phase,
     * and returns platform_names + platform_candidates discovered.
     *
     * Fields left blank (SERP provider's responsibility):
     *   bio, social_urls, country, language, source_urls, aliases.
     */
    public function lookup( int $post_id, string $model_name ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' ) {
            return [ 'status' => 'error', 'message' => __( 'Model name is empty.', 'tmwseo' ) ];
        }

        if ( Settings::is_safe_mode() ) {
            return [
                'status'  => 'no_provider',
                'message' => __( 'Safe Mode is enabled — all external calls are suppressed.', 'tmwseo' ),
            ];
        }

        Logs::info( 'model_research', '[TMW-PROBE-PROVIDER] Direct probe provider started', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
        ] );

        $seeds = $this->build_name_seeds( $model_name );

        if ( empty( $seeds ) ) {
            return [
                'status'               => 'partial',
                'message'              => __( 'Could not derive handle seeds from model name.', 'tmwseo' ),
                'display_name'         => $model_name,
                'aliases'              => [],
                'bio'                  => '',
                'platform_names'       => [],
                'social_urls'          => [],
                'platform_candidates'  => [],
                'field_confidence'     => $this->empty_field_confidence(),
                'research_diagnostics' => [ 'platform_probe' => [] ],
                'country'              => '',
                'language'             => '',
                'source_urls'          => [],
                'confidence'           => 0,
                'notes'                => 'Direct probe: no handle seeds derived from model name.',
            ];
        }

        // Run the probe. already_confirmed is empty because this provider
        // runs independently — the pipeline deduplicates results at merge time.
        $probe       = $this->make_probe();
        $probe_result = $probe->run( $seeds, [], $post_id );

        $verified    = $probe_result['verified_urls'];
        $diagnostics = $probe_result['diagnostics'];

        $n_attempted = (int) ( $diagnostics['probes_attempted'] ?? 0 );
        $n_accepted  = (int) ( $diagnostics['probes_accepted'] ?? 0 );
        $seeds_used  = (int) ( $diagnostics['seeds_used'] ?? 0 );

        if ( empty( $verified ) ) {
            $status = $n_attempted > 0 ? 'partial' : 'partial';
            return [
                'status'               => $status,
                'display_name'         => $model_name,
                'aliases'              => [],
                'bio'                  => '',
                'platform_names'       => [],
                'social_urls'          => [],
                'platform_candidates'  => [],
                'field_confidence'     => $this->empty_field_confidence(),
                'research_diagnostics' => [ 'platform_probe' => $diagnostics ],
                'country'              => '',
                'language'             => '',
                'source_urls'          => [],
                'confidence'           => 0,
                'notes'                => sprintf(
                    'Direct probe: %d probe(s) attempted, 0 platform(s) found.',
                    $n_attempted
                ),
            ];
        }

        // ── Convert verified_urls to platform_names + platform_candidates ────
        $platform_names  = [];
        $seen_slugs      = [];
        $candidates      = [];

        foreach ( $verified as $url => $entry ) {
            $slug  = (string) ( $entry['slug'] ?? '' );
            $parse = (array) ( $entry['parse'] ?? [] );

            if ( $slug === '' || empty( $parse['success'] ) ) {
                continue;
            }

            // Candidate row — same shape as SERP provider, tagged with probe source.
            $row               = array_merge( [ 'source_url' => $url ], $parse );
            $row['discovered_via_probe'] = true;
            $candidates[]      = $row;

            if ( ! isset( $seen_slugs[ $slug ] ) ) {
                $seen_slugs[ $slug ] = true;
                $platform_data       = PlatformRegistry::get( $slug );
                $platform_names[]    = is_array( $platform_data )
                    ? (string) ( $platform_data['name'] ?? ucfirst( $slug ) )
                    : ucfirst( str_replace( '_', ' ', $slug ) );
            }
        }

        $n_platforms = count( $platform_names );
        $confidence  = $this->compute_confidence( $n_platforms );

        Logs::info( 'model_research', '[TMW-PROBE-PROVIDER] Direct probe provider complete', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
            'platforms'  => $n_platforms,
            'confidence' => $confidence,
        ] );

        return [
            'status'               => 'ok',
            'display_name'         => $model_name,
            'aliases'              => [],
            'bio'                  => '',
            'platform_names'       => $platform_names,
            'social_urls'          => [],
            'platform_candidates'  => $candidates,
            'field_confidence'     => [
                'platform_names' => $confidence,
                'social_urls'    => 0,
                'bio'            => 0,
                'country'        => 0,
                'language'       => 0,
                'source_urls'    => 0,
            ],
            'research_diagnostics' => [ 'platform_probe' => $diagnostics ],
            'country'              => '',
            'language'             => '',
            'source_urls'          => [],
            'confidence'           => $confidence,
            'notes'                => sprintf(
                'Direct probe: %d platform(s) found from %d seed(s), %d probe(s) attempted.',
                $n_platforms,
                $seeds_used,
                $n_attempted
            ),
        ];
    }

    // ── Provider registration ─────────────────────────────────────────────────

    /**
     * Register this provider on the tmwseo_research_providers filter.
     *
     * Uses priority 20 so this provider runs AFTER the DataForSEO SERP provider
     * (which registers at priority 10). The pipeline merges results in order;
     * running SERP first ensures broad discovery data is already in the pool
     * when the probe phase executes.
     *
     * Called from ModelHelper::init() unconditionally — the probe phase requires
     * no external API keys.
     */
    public static function maybe_register(): void {
        add_filter(
            'tmwseo_research_providers',
            static function ( array $providers ): array {
                $providers[] = new self();
                return $providers;
            },
            20
        );
    }

    // ── Seed generation ───────────────────────────────────────────────────────

    /**
     * Build name-derived handle seeds from the model display name.
     *
     * Produces one seed: the display name with spaces and punctuation stripped,
     * preserving original CamelCase. This is the "name_derived" seed (tier 1
     * priority in ModelPlatformProbe's seed scoring).
     *
     * Why only one seed here: the SERP provider's build_handle_seeds() can
     * extract additional handles from pass-one SERP results. This provider
     * has no SERP results, so the only reliable anchor is the display name.
     * Generating speculative variant seeds (lowercase, hyphenated, etc.) with
     * no SERP corroboration would increase false-positive probe attempts.
     *
     * @param  string $model_name Display name / post title.
     * @return array<int,array{handle:string,source_platform:string,source_url:string}>
     */
    protected function build_name_seeds( string $model_name ): array {
        $name_clean = (string) preg_replace( '/[^A-Za-z0-9]/', '', $model_name );
        if ( $name_clean === '' ) {
            return [];
        }
        return [
            [
                'handle'          => $name_clean,
                'source_platform' => 'name_derived',
                'source_url'      => '',
            ],
        ];
    }

    // ── Probe factory ─────────────────────────────────────────────────────────

    /**
     * Instantiate the probe object.
     *
     * Extracted into a protected method so test subclasses can inject a mock
     * probe without making live HTTP calls.
     *
     * @return ModelPlatformProbe
     */
    protected function make_probe(): ModelPlatformProbe {
        if ( ! class_exists( ModelPlatformProbe::class ) ) {
            require_once __DIR__ . '/class-model-platform-probe.php';
        }
        return new ModelPlatformProbe();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute a conservative confidence score for this provider's output.
     *
     * Direct probe confidence is deliberately lower than SERP confidence at
     * equivalent platform counts because the probe is a targeted check, not
     * broad corroboration. The merge step may add a small corroboration bonus.
     *
     * @param  int $n_platforms Number of confirmed platform profiles found.
     * @return int Confidence score 0–50.
     */
    private function compute_confidence( int $n_platforms ): int {
        return match ( true ) {
            $n_platforms === 0 => 0,
            $n_platforms === 1 => 20,
            $n_platforms === 2 => 35,
            default            => 50,
        };
    }

    /**
     * Return a zeroed-out field_confidence map.
     *
     * Used for partial/empty result returns to maintain a consistent shape.
     *
     * @return array<string,int>
     */
    private function empty_field_confidence(): array {
        return [
            'platform_names' => 0,
            'social_urls'    => 0,
            'bio'            => 0,
            'country'        => 0,
            'language'       => 0,
            'source_urls'    => 0,
        ];
    }
}
