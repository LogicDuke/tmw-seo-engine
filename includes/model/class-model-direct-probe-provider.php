<?php
/**
 * TMW SEO Engine — Direct Probe Research Provider (v4.6.9)
 *
 * Complements the DataForSEO SERP provider with direct HTTP platform-profile
 * recall. In v4.6.9 this provider implements ModelContextAwareProvider so the
 * pipeline can feed it high-quality handles discovered by the SERP provider
 * during the same run, replacing the earlier name-only-derived seed approach.
 *
 * Architecture:
 *   - Implements ModelResearchProvider + ModelContextAwareProvider.
 *   - Registered at filter priority 20 (SERP is 10 → SERP always runs first).
 *   - Before lookup(), the pipeline calls set_prior_results() with SERP results.
 *   - lookup() extracts discovered_handles_structured from SERP results, builds a
 *     prioritized seed list, then delegates to ModelPlatformProbe.
 *
 * Seed priority order (v4.6.9):
 *   Tier 1 — verified_extract: adult cam-platform handles from SERP structured
 *             extraction (e.g. username from chaturbate.com/username in SERP).
 *   Tier 1 — name_derived: model display name with punctuation stripped.
 *   Tier 2 — adult cam platform seeds (chaturbate, stripchat, camsoda, …).
 *   Tier 3 — social/hub platform seeds (fansly, linktree, twitter, …).
 *
 * Variant generation:
 *   For the top 1 seed only, up to MAX_SEED_VARIANTS lowercase/CamelCase
 *   variants are generated and appended at name_derived priority.
 *   Total seeds capped at MAX_SEEDS.
 *
 * Probe scheduling:
 *   CORE_PRIORITY_SLUGS (CamSoda, Chaturbate, Stripchat) are guaranteed at
 *   least one probe with the best available seed before the round-robin budget
 *   is consumed for other platforms.
 *
 * Trust guarantees (unchanged from v4.6.8):
 *   - platform_names only from successful parse_url_for_platform_structured().
 *   - No manual usernames or verified data is overwritten.
 *   - Safe Mode suppresses all probes.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.8
 * @updated 4.6.9 — handle-sharing via ModelContextAwareProvider
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Platform\PlatformRegistry;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Direct-probe research provider: platform recall via HTTP probing.
 *
 * Consumes high-confidence handles from prior providers (SERP) and probes
 * canonical platform URLs using ModelPlatformProbe. All results still flow
 * through PlatformProfiles::parse_url_for_platform_structured() before
 * being admitted as platform_candidates.
 */
class ModelDirectProbeProvider implements ModelResearchProvider, ModelContextAwareProvider {

    /**
     * Maximum total seeds passed to ModelPlatformProbe.
     * Includes shared handles + name-derived + variants.
     *
     * @var int
     */
    private const MAX_SEEDS = 5;

    /**
     * Maximum handle variants generated from the top seed.
     *
     * Applied only to the single highest-priority seed to avoid seed explosion.
     *
     * @var int
     */
    private const MAX_SEED_VARIANTS = 3;

    /**
     * Results from providers that ran earlier in the current pipeline run.
     * Populated by set_prior_results() before lookup() is called.
     *
     * @var array<string,array>
     */
    private array $prior_results = [];

    // ── ModelContextAwareProvider ─────────────────────────────────────────────

    /**
     * Receive accumulated results from earlier providers before lookup().
     *
     * The pipeline calls this immediately before lookup() when this provider
     * is registered. Stores the snapshot for use inside lookup().
     *
     * {@inheritdoc}
     */
    public function set_prior_results( array $prior_results ): void {
        $this->prior_results = $prior_results;
    }

    // ── ModelResearchProvider ─────────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function provider_name(): string {
        return 'direct_probe';
    }

    /**
     * {@inheritdoc}
     *
     * Builds a prioritized seed list from shared SERP handles + name-derived
     * variants, runs ModelPlatformProbe, and returns platform_names +
     * platform_candidates.
     *
     * Fields intentionally left blank (SERP provider's responsibility):
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
            'post_id'             => $post_id,
            'model_name'          => $model_name,
            'prior_providers'     => array_keys( $this->prior_results ),
        ] );

        // ── Build seeds: shared handles from SERP + name-derived variants ─────
        $shared_handles = $this->collect_shared_handles();
        $seeds          = $this->build_seeds_for_probe( $model_name, $shared_handles );

        if ( empty( $seeds ) ) {
            return [
                'status'               => 'partial',
                'message'              => __( 'Could not derive handle seeds.', 'tmwseo' ),
                'display_name'         => $model_name,
                'aliases'              => [],
                'bio'                  => '',
                'platform_names'       => [],
                'social_urls'          => [],
                'platform_candidates'  => [],
                'field_confidence'     => $this->empty_field_confidence(),
                'research_diagnostics' => [
                    'platform_probe'  => [],
                    'shared_handles'  => $shared_handles,
                    'seeds_built'     => [],
                ],
                'country'              => '',
                'language'             => '',
                'source_urls'          => [],
                'confidence'           => 0,
                'notes'                => 'Direct probe: no seeds derived.',
            ];
        }

        // ── Run probe ─────────────────────────────────────────────────────────
        $probe        = $this->make_probe();
        $probe_result = $probe->run( $seeds, [], $post_id );

        $verified    = $probe_result['verified_urls'];
        $diagnostics = $probe_result['diagnostics'];

        $n_attempted = (int) ( $diagnostics['probes_attempted'] ?? 0 );
        $seeds_used  = (int) ( $diagnostics['seeds_used'] ?? 0 );

        if ( empty( $verified ) ) {
            return [
                'status'               => 'partial',
                'display_name'         => $model_name,
                'aliases'              => [],
                'bio'                  => '',
                'platform_names'       => [],
                'social_urls'          => [],
                'platform_candidates'  => [],
                'field_confidence'     => $this->empty_field_confidence(),
                'research_diagnostics' => [
                    'platform_probe' => $diagnostics,
                    'shared_handles' => $shared_handles,
                    'seeds_built'    => $seeds,
                ],
                'country'              => '',
                'language'             => '',
                'source_urls'          => [],
                'confidence'           => 0,
                'notes'                => sprintf(
                    'Direct probe: %d probe(s) attempted from %d seed(s), 0 platform(s) found.',
                    $n_attempted,
                    $seeds_used
                ),
            ];
        }

        // ── Convert verified_urls → platform_names + platform_candidates ──────
        $platform_names = [];
        $seen_slugs     = [];
        $candidates     = [];

        foreach ( $verified as $url => $entry ) {
            $slug  = (string) ( $entry['slug'] ?? '' );
            $parse = (array) ( $entry['parse'] ?? [] );

            if ( $slug === '' || empty( $parse['success'] ) ) {
                continue;
            }

            $row                       = array_merge( [ 'source_url' => $url ], $parse );
            $row['discovered_via_probe'] = true;
            $candidates[]              = $row;

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
            'post_id'         => $post_id,
            'model_name'      => $model_name,
            'shared_handles'  => count( $shared_handles ),
            'seeds_built'     => count( $seeds ),
            'platforms_found' => $n_platforms,
            'confidence'      => $confidence,
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
            'research_diagnostics' => [
                'platform_probe' => $diagnostics,
                'shared_handles' => $shared_handles,
                'seeds_built'    => $seeds,
            ],
            'country'              => '',
            'language'             => '',
            'source_urls'          => [],
            'confidence'           => $confidence,
            'notes'                => sprintf(
                'Direct probe: %d platform(s) found from %d seed(s) (%d shared, %d probe(s) attempted).',
                $n_platforms,
                count( $seeds ),
                count( $shared_handles ),
                $n_attempted
            ),
        ];
    }

    // ── Provider registration ─────────────────────────────────────────────────

    /**
     * Register on tmwseo_research_providers at priority 20.
     *
     * Priority 20 ensures this provider runs AFTER DataForSEO SERP (priority 10),
     * so prior results contain SERP-discovered handles when set_prior_results()
     * is called.
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

    // ── Handle collection from prior results ──────────────────────────────────

    /**
     * Collect discovered_handles_structured from all prior provider results.
     *
     * Reads the `discovered_handles_structured` field exposed by
     * ModelSerpResearchProvider (and any other provider that emits it).
     * Items are returned in the order received; duplicates (same lowercase
     * handle) are removed, keeping the first occurrence.
     *
     * @return array<int,array{handle:string,source_platform:string,source_url:string,method:string,tier:int}>
     */
    protected function collect_shared_handles(): array {
        $handles    = [];
        $seen_lc    = [];

        foreach ( $this->prior_results as $result ) {
            if ( ! is_array( $result ) ) {
                continue;
            }
            $structured = $result['discovered_handles_structured'] ?? [];
            if ( ! is_array( $structured ) ) {
                continue;
            }
            foreach ( $structured as $h ) {
                if ( ! is_array( $h ) ) {
                    continue;
                }
                $handle = trim( (string) ( $h['handle'] ?? '' ) );
                if ( $handle === '' ) {
                    continue;
                }
                $lc = strtolower( $handle );
                if ( ! isset( $seen_lc[ $lc ] ) ) {
                    $seen_lc[ $lc ] = true;
                    $handles[] = [
                        'handle'          => $handle,
                        'source_platform' => (string) ( $h['source_platform'] ?? 'shared' ),
                        'source_url'      => (string) ( $h['source_url'] ?? '' ),
                        'method'          => (string) ( $h['method'] ?? 'structured_platform' ),
                        'tier'            => (int)    ( $h['tier'] ?? 2 ),
                    ];
                }
            }
        }

        return $handles;
    }

    // ── Seed construction ─────────────────────────────────────────────────────

    /**
     * Build a prioritized seed list for ModelPlatformProbe.
     *
     * Priority order:
     *   1. Shared handles tier 1 (SERP structured adult-platform extractions).
     *   2. Shared handles tier 2 (social/hub extractions).
     *   3. Name-derived seed (display name with punctuation stripped).
     *   4. Handle variants of the top 1 seed (up to MAX_SEED_VARIANTS).
     *
     * All seeds arrive at ModelPlatformProbe with a `source_platform` value
     * that maps into SEED_SOURCE_PRIORITY tiers for probe scheduling:
     *   - 'verified_extract': tier 1 (SERP structured extraction)
     *   - 'name_derived': tier 1 (display name)
     *   - hub/social slugs: tier 3+
     *
     * Total seeds capped at MAX_SEEDS.
     *
     * @param  string $model_name     Model display name / post title.
     * @param  array  $shared_handles Output of collect_shared_handles().
     * @return array<int,array{handle:string,source_platform:string,source_url:string}>
     */
    protected function build_seeds_for_probe( string $model_name, array $shared_handles ): array {
        $seeds   = [];
        $seen_lc = [];

        // ── Phase 1: shared handles sorted by tier (tier 1 first) ────────────
        $sorted_shared = $shared_handles;
        usort( $sorted_shared, static fn( array $a, array $b ): int =>
            ( $a['tier'] ?? 5 ) <=> ( $b['tier'] ?? 5 )
        );

        foreach ( $sorted_shared as $h ) {
            if ( count( $seeds ) >= self::MAX_SEEDS ) {
                break;
            }
            $handle = (string) ( $h['handle'] ?? '' );
            if ( $handle === '' ) {
                continue;
            }
            $lc = strtolower( $handle );
            if ( isset( $seen_lc[ $lc ] ) ) {
                continue;
            }
            $seen_lc[ $lc ] = true;

            // Map SERP handle tier to probe source_platform:
            //   tier 1 (structured_platform) → 'verified_extract' → probe tier 1
            //   tier 2+ (social_hub etc.)    → keep original slug  → probe tier 3+
            $probe_source = ( ( $h['method'] ?? '' ) === 'structured_platform' )
                ? 'verified_extract'
                : (string) ( $h['source_platform'] ?? 'shared' );

            $seeds[] = [
                'handle'          => $handle,
                'source_platform' => $probe_source,
                'source_url'      => (string) ( $h['source_url'] ?? '' ),
            ];
        }

        // ── Phase 2: name-derived seed ────────────────────────────────────────
        $name_clean = (string) preg_replace( '/[^A-Za-z0-9]/', '', $model_name );
        if ( $name_clean !== '' && ! isset( $seen_lc[ strtolower( $name_clean ) ] ) ) {
            $seen_lc[ strtolower( $name_clean ) ] = true;
            $seeds[] = [
                'handle'          => $name_clean,
                'source_platform' => 'name_derived',
                'source_url'      => '',
            ];
        }

        // ── Phase 3: variants of the top seed only ────────────────────────────
        // Bounded variant generation for the single best available seed.
        // Variants use 'name_derived' source_platform (probe tier 1) to ensure
        // they are not deprioritized below weaker social seeds.
        if ( ! empty( $seeds ) ) {
            $top_handle = (string) ( $seeds[0]['handle'] ?? '' );
            $variants   = $this->generate_bounded_variants( $top_handle );
            $added      = 0;

            foreach ( $variants as $variant ) {
                if ( count( $seeds ) >= self::MAX_SEEDS || $added >= self::MAX_SEED_VARIANTS ) {
                    break;
                }
                $vlc = strtolower( $variant );
                if ( ! isset( $seen_lc[ $vlc ] ) ) {
                    $seen_lc[ $vlc ] = true;
                    $seeds[] = [
                        'handle'          => $variant,
                        'source_platform' => 'name_derived',
                        'source_url'      => '',
                    ];
                    $added++;
                }
            }
        }

        return array_slice( $seeds, 0, self::MAX_SEEDS );
    }

    /**
     * Generate a small set of normalized handle variants for probing.
     *
     * Applied only to the top seed. Produces lowercase, CamelCase, and
     * lowerCamel forms for handles that contain multiple "words" (detected by
     * embedded uppercase letters or hyphen/underscore separators). Single
     * all-lowercase handles produce no useful variants and return [].
     *
     * Examples:
     *   'AnisyiaCam'  → ['anisyiacam', 'anisyiaCam']
     *   'abby-murray' → ['AbbyMurray', 'abbyMurray', 'abbymurray']
     *   'anisyia'     → [] (no meaningful variants for single-word lowercase)
     *
     * @param  string $handle  The top seed's handle string.
     * @return string[]        Unique variant strings (excluding the original).
     */
    protected function generate_bounded_variants( string $handle ): array {
        if ( $handle === '' ) {
            return [];
        }

        // Split into word tokens on: uppercase transitions, hyphens, underscores.
        preg_match_all( '/[A-Za-z0-9]+/u', $handle, $matches );
        $raw_tokens = array_values( array_filter( array_map( 'strval', $matches[0] ?? [] ) ) );

        // Further split CamelCase tokens into sub-words.
        $tokens = [];
        foreach ( $raw_tokens as $tok ) {
            $split = (array) preg_split( '/(?<=[a-z])(?=[A-Z])/', $tok );
            foreach ( $split as $sub ) {
                $sub = trim( (string) $sub );
                if ( $sub !== '' ) {
                    $tokens[] = $sub;
                }
            }
        }

        if ( count( $tokens ) < 2 ) {
            // Single-word handle: only add lowercase if different from original.
            $lower = strtolower( $handle );
            return ( $lower !== $handle ) ? [ $lower ] : [];
        }

        $lower_tokens = array_map( 'strtolower', $tokens );
        $camel_tokens = array_map( static fn( string $t ): string => ucfirst( strtolower( $t ) ), $tokens );

        $candidates = [
            implode( '', $lower_tokens ),                       // lowercase joined
            implode( '', $camel_tokens ),                       // CamelCase
            lcfirst( implode( '', $camel_tokens ) ),            // lowerCamel
        ];

        $seen    = [ $handle => true ]; // exclude only the exact original string
        $results = [];
        foreach ( $candidates as $c ) {
            $c = trim( $c );
            if ( $c === '' || isset( $seen[ $c ] ) ) {
                continue;
            }
            $seen[ $c ] = true;
            $results[]  = $c;
        }

        return $results;
    }

    // ── Probe factory ─────────────────────────────────────────────────────────

    /**
     * Instantiate the probe object.
     *
     * Extracted as a protected method so test subclasses can inject a mock
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
