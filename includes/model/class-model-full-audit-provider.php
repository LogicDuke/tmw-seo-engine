<?php
/**
 * TMW SEO Engine — Full Platform Audit Provider
 *
 * Runs an exhaustive search across EVERY platform in PlatformRegistry.
 * Designed to answer: "which platforms from the full configured list is
 * this model already active on?"
 *
 * Architecture differences from the fast editor mode (ModelSerpResearchProvider
 * + ModelDirectProbeProvider):
 *
 * SERP layer (via ModelSerpResearchProvider internals):
 *   - SERP depth: 20 results per query (restored from SYNC_SERP_DEPTH=8).
 *   - Hub expansion: enabled (up to 3 hub pages, restored from 0).
 *   - Pass-two: enabled (handle-seeded SERP confirmation, restored from disabled).
 *   - All aliases used (up to AUDIT_ALIAS_CAP=10, raised from 3).
 *   - Each alias generates 3 query families instead of 2.
 *   - Exhaustive "full registry sweep" query using every registered domain.
 *
 * Probe layer (via ModelPlatformProbe::run_full_audit()):
 *   - Budget: 40 probes (covers all 32 registry slugs + buffer).
 *   - No CORE_PRIORITY_SLUGS favoritism.
 *   - All registry platforms get equal probe access, not just PROBE_PRIORITY_SLUGS.
 *   - fansly, linktree, allmylinks etc. are probed.
 *   - Returns per-platform coverage: confirmed / rejected / not_probed.
 *
 * Safety guarantees (unchanged):
 *   - All probe-accepted URLs still flow through
 *     PlatformProfiles::parse_url_for_platform_structured().
 *   - Manual usernames in _tmwseo_platform_username_* are never overwritten.
 *   - Safe Mode suppresses all external calls.
 *   - Existing promote/dismiss workflow is unchanged.
 *
 * Result extras (beyond fast mode):
 *   - research_diagnostics.platform_coverage — per-platform status map.
 *   - research_diagnostics.audit_mode = true — flag for UI to show full panel.
 *   - notes includes a plain-language summary of coverage.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.7.0
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( ModelOutboundHarvester::class ) ) {
    $__tmw_harvester_file = __DIR__ . '/class-model-outbound-harvester.php';
    if ( file_exists( $__tmw_harvester_file ) ) {
        require_once $__tmw_harvester_file;
    }
    unset( $__tmw_harvester_file );
}

/**
 * Full platform audit research provider.
 *
 * Intended for exhaustive discovery, not quick/interactive use.
 * Uses ModelSerpResearchProvider's audit-mode helpers and
 * ModelPlatformProbe::run_full_audit() for complete platform coverage.
 */
class ModelFullAuditProvider extends ModelSerpResearchProvider {

    /**
     * {@inheritdoc}
     */
    public function provider_name(): string {
        return 'full_audit';
    }

    /**
     * Run the full platform audit.
     *
     * Overrides the parent lookup() to use audit-mode constants and helpers.
     * All safety and trust guarantees from the parent are preserved.
     *
     * {@inheritdoc}
     */
    public function lookup( int $post_id, string $model_name ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' ) {
            return [ 'status' => 'error', 'message' => __( 'Model name is empty.', 'tmwseo' ) ];
        }

        if ( ! DataForSEO::is_configured() ) {
            // Fall back to probe-only audit when SERP is not available.
            return $this->probe_only_audit( $post_id, $model_name );
        }

        if ( Settings::is_safe_mode() ) {
            return [
                'status'  => 'no_provider',
                'message' => __( 'Safe Mode is enabled — all external API calls are suppressed.', 'tmwseo' ),
            ];
        }

        Logs::info( 'model_research', '[TMW-AUDIT] Full audit started', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
        ] );

        $t_start = microtime( true );

        // ── Read aliases — no cap beyond AUDIT_ALIAS_CAP ─────────────────────
        $aliases_raw = trim( (string) get_post_meta( $post_id, \TMWSEO\Engine\Admin\ModelHelper::META_ALIASES, true ) );
        $aliases     = [];
        if ( $aliases_raw !== '' ) {
            foreach ( (array) preg_split( '/\s*,\s*/', $aliases_raw ) as $alias ) {
                $alias = trim( (string) $alias );
                if ( $alias !== '' && strtolower( $alias ) !== strtolower( $model_name ) ) {
                    $aliases[] = $alias;
                }
            }
        }

        // ── PASS ONE: full audit query pack ───────────────────────────────────
        $queries_p1 = $this->build_query_pack_audit( $model_name, $aliases );
        $pack_p1    = $this->run_query_pack_pub( $queries_p1, parent::AUDIT_SERP_DEPTH, $post_id );

        if ( $pack_p1['succeeded'] === 0 ) {
            Logs::warn( 'model_research', '[TMW-AUDIT] All SERP queries failed — falling back to probe-only', [
                'post_id' => $post_id,
            ] );
            return $this->probe_only_audit( $post_id, $model_name );
        }

        $merged_p1      = $this->merge_serp_items_pub( $pack_p1['items'] );
        $p1_candidates  = $this->extract_candidates_from_items_pub( $merged_p1['items'] );
        $p1_successful  = array_values( array_filter( $p1_candidates, static fn( $c ) => ! empty( $c['success'] ) ) );

        $already_confirmed = [];
        foreach ( $p1_successful as $c ) {
            $slug = (string) ( $c['normalized_platform'] ?? '' );
            if ( $slug !== '' ) { $already_confirmed[ $slug ] = true; }
        }

        // ── Handle seeds with raised cap ──────────────────────────────────────
        $handle_seeds = $this->build_handle_seeds_audit( $p1_successful, $model_name );

        // ── PASS TWO: handle-seeded SERP confirmation (enabled in audit mode) ─
        $conf_log = [];
        $items_p2 = [];
        if ( parent::AUDIT_PASS_TWO ) {
            $pass_two = $this->run_confirmation_pass_audit( $handle_seeds, $already_confirmed, $post_id );
            $items_p2 = $pass_two['items'];
            $conf_log = $pass_two['confirmation_log'];
        }

        // ── PASS THREE: full-registry probe ───────────────────────────────────
        $probe_result      = $this->run_full_audit_probe( $handle_seeds, $post_id );
        $probe_urls        = array_keys( $probe_result['verified_urls'] );
        $probe_diagnostics = $probe_result['diagnostics'];

        // ── PASS FOUR: outbound-link harvest (fallback recall) ───────────────
        // One-hop harvest of <a href> links from already-confirmed link-hub
        // pages (Beacons, Linktree, AllMyLinks, solo.to, Carrd) and from
        // SERP-surfaced Facebook pages. Each extracted URL is re-parsed by
        // PlatformProfiles::parse_url_for_platform_structured() — same strict
        // trust gate as the probe — and must substring-match a known seed
        // handle. Harvest URLs are merged into the probe URL set so the
        // downstream parser treats them identically.
        $harvest_seed_pages      = $this->collect_harvest_seed_pages(
            $probe_result,
            $pack_p1['items'] ?? []
        );
        $harvest_result          = $this->run_outbound_harvest(
            $harvest_seed_pages,
            $handle_seeds,
            $post_id
        );
        $harvest_discovered      = $harvest_result['discovered'] ?? [];
        $harvest_diagnostics     = $harvest_result['diagnostics'] ?? [];
        $harvest_evidence_by_url = [];
        foreach ( $harvest_discovered as $h ) {
            $u = (string) ( $h['normalized_url'] ?? '' );
            if ( $u === '' ) { continue; }
            $harvest_evidence_by_url[ $u ] = (array) ( $h['evidence'] ?? [] );
            if ( ! in_array( $u, $probe_urls, true ) ) {
                $probe_urls[] = $u;
            }
        }

        // ── Merge and parse ───────────────────────────────────────────────────
        $all_items    = array_merge( $pack_p1['items'], $items_p2 );
        $merged_all   = $this->merge_serp_items_pub( $all_items );
        $query_stats  = array_merge( $pack_p1['query_stats'], $pass_two['query_stats'] ?? [] );

        $t_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );
        Logs::info( 'model_research', '[TMW-AUDIT] Full audit complete', [
            'post_id'         => $post_id,
            'duration_ms'     => $t_ms,
            'p1_queries'      => count( $queries_p1 ),
            'p1_succeeded'    => $pack_p1['succeeded'],
            'p2_items'        => count( $items_p2 ),
            'probe_accepted'  => (int) ( $probe_diagnostics['probes_accepted'] ?? 0 ),
            'platforms_confirmed' => count( array_filter(
                $probe_diagnostics['platform_coverage'] ?? [],
                static fn( $p ) => ( $p['status'] ?? '' ) === 'confirmed'
            ) ),
        ] );

        $result = $this->parse_merged_items_pub(
            $model_name,
            $merged_all,
            $pack_p1['succeeded'],
            count( $queries_p1 ),
            $query_stats,
            $handle_seeds,
            $conf_log,
            $probe_urls,
            $probe_diagnostics
        );

        // ── Enrich result with audit-specific metadata and diagnostics ───────
        $result['research_diagnostics']['audit_mode']         = true;
        $result['research_diagnostics']['platform_coverage']  =
            $probe_diagnostics['platform_coverage'] ?? [];

        // Attach outbound-harvest evidence to matching platform_candidates and
        // expose the harvest diagnostics block so operators can audit recall.
        if ( ! empty( $harvest_evidence_by_url ) && ! empty( $result['platform_candidates'] ) ) {
            foreach ( $result['platform_candidates'] as $ci => $cand ) {
                $c_url = (string) ( $cand['normalized_url'] ?? '' );
                if ( $c_url === '' ) {
                    $c_url = (string) ( $cand['source_url'] ?? '' );
                }
                if ( $c_url !== '' && isset( $harvest_evidence_by_url[ $c_url ] ) ) {
                    $result['platform_candidates'][ $ci ]['discovered_via_outbound_harvest'] = true;
                    $result['platform_candidates'][ $ci ]['evidence'] = $harvest_evidence_by_url[ $c_url ];
                }
            }
        }
        $result['research_diagnostics']['outbound_harvest'] = $harvest_diagnostics;
        $result['research_diagnostics']['audit_config'] = [
            'serp_depth_used'          => parent::AUDIT_SERP_DEPTH,
            'pass_two_enabled'         => parent::AUDIT_PASS_TWO,
            'hub_pages_limit'          => parent::AUDIT_MAX_HUB_PAGES,
            'alias_cap'                => parent::AUDIT_ALIAS_CAP,
            'seed_cap'                 => parent::AUDIT_SEED_CAP,
            'handle_variant_cap'       => parent::AUDIT_MAX_HANDLE_VARIANTS,
            'total_queries_built'      => count( $queries_p1 ),
            'queries_succeeded'        => $pack_p1['succeeded'],
            'aliases_used'             => count( $aliases ),
            'seeds_built'              => count( $handle_seeds ),
            'probes_attempted'         => (int) ( $probe_diagnostics['probes_attempted'] ?? 0 ),
            'probes_accepted'          => (int) ( $probe_diagnostics['probes_accepted'] ?? 0 ),
            'platforms_in_registry'    => count( \TMWSEO\Engine\Platform\PlatformRegistry::get_slugs() ),
            'platforms_checked'        => count( array_filter(
                $probe_diagnostics['platform_coverage'] ?? [],
                static fn( $p ) => ( $p['status'] ?? '' ) !== 'not_probed'
            ) ),
            'platforms_confirmed'      => count( array_filter(
                $probe_diagnostics['platform_coverage'] ?? [],
                static fn( $p ) => ( $p['status'] ?? '' ) === 'confirmed'
            ) ),
            'duration_ms'              => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
            'query_families_used'      => array_values( array_unique(
                array_column( $pack_p1['query_stats'], 'family' )
            ) ),
            'full_registry_sweep_included' => in_array(
                'full_registry_sweep',
                array_column( $queries_p1, 'family' ),
                true
            ),
        ];

        $n_confirmed  = count( array_filter(
            $probe_diagnostics['platform_coverage'] ?? [],
            static fn( $p ) => ( $p['status'] ?? '' ) === 'confirmed'
        ) );
        $n_rejected   = count( array_filter(
            $probe_diagnostics['platform_coverage'] ?? [],
            static fn( $p ) => ( $p['status'] ?? '' ) === 'rejected'
        ) );
        $n_not_probed = count( array_filter(
            $probe_diagnostics['platform_coverage'] ?? [],
            static fn( $p ) => ( $p['status'] ?? '' ) === 'not_probed'
        ) );

        $coverage_note = sprintf(
            'Full audit: %d platform(s) confirmed, %d rejected, %d not probed (budget). SERP: %d/%d queries. Probe: %d/%d accepted.',
            $n_confirmed,
            $n_rejected,
            $n_not_probed,
            $pack_p1['succeeded'],
            count( $queries_p1 ),
            (int) ( $probe_diagnostics['probes_accepted'] ?? 0 ),
            (int) ( $probe_diagnostics['probes_attempted'] ?? 0 )
        );
        $result['notes'] = $coverage_note . ' | ' . $result['notes'];

        return $result;
    }

    // ── Public wrappers for protected parent methods ──────────────────────────
    // These allow the audit subclass to call parent private/protected methods
    // that share the same trust logic without duplicating code.

    protected function run_query_pack_pub( array $queries, int $depth, int $post_id ): array {
        // Temporarily patch SYNC_SERP_DEPTH via a custom run that passes depth explicitly.
        $all_items   = [];
        $query_stats = [];
        $succeeded   = 0;
        $failed      = 0;
        $last_error  = null;

        foreach ( $queries as $idx => $descriptor ) {
            $query  = trim( (string) ( $descriptor['query'] ?? '' ) );
            $family = trim( (string) ( $descriptor['family'] ?? 'generic' ) );
            if ( $query === '' ) { continue; }

            $t_start = microtime( true );
            $serp    = DataForSEO::serp_live( $query, $depth );
            $t_ms    = (int) round( ( microtime( true ) - $t_start ) * 1000 );

            if ( empty( $serp['ok'] ) ) {
                $last_error    = (string) ( $serp['error'] ?? 'unknown_error' );
                $failed++;
                $query_stats[] = [ 'family' => $family, 'query' => $query, 'ok' => false,
                    'result_count' => 0, 'error' => $last_error, 'duration_ms' => $t_ms ];
                continue;
            }

            $items = (array) ( $serp['items'] ?? [] );
            $succeeded++;
            $query_stats[] = [ 'family' => $family, 'query' => $query, 'ok' => true,
                'result_count' => count( $items ), 'error' => '', 'duration_ms' => $t_ms ];

            $alias_source = trim( (string) ( $descriptor['_alias_source'] ?? '' ) );
            foreach ( $items as $item ) {
                $item['_query']        = $query;
                $item['_query_index']  = $idx;
                $item['_query_family'] = $family;
                if ( $alias_source !== '' ) { $item['_alias_source'] = $alias_source; }
                $all_items[]           = $item;
            }
        }

        return [
            'succeeded'   => $succeeded,
            'failed'      => $failed,
            'last_error'  => $last_error,
            'items'       => $all_items,
            'query_stats' => $query_stats,
        ];
    }

    protected function merge_serp_items_pub( array $raw_items ): array {
        return $this->merge_serp_items( $raw_items );
    }

    protected function extract_candidates_from_items_pub( array $items ): array {
        return $this->extract_candidates_from_items( $items );
    }

    protected function parse_merged_items_pub(
        string $model_name,
        array $merged,
        int $succeeded,
        int $total_queries,
        array $query_stats,
        array $handle_seeds = [],
        array $conf_log = [],
        array $probe_candidate_urls = [],
        array $probe_diagnostics = []
    ): array {
        return $this->parse_merged_items(
            $model_name, $merged, $succeeded, $total_queries, $query_stats,
            $handle_seeds, $conf_log, $probe_candidate_urls, $probe_diagnostics
        );
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Probe-only audit when DataForSEO is not configured.
     * Uses name-derived seeds and runs run_full_audit() across all platforms.
     */
    private function probe_only_audit( int $post_id, string $model_name ): array {
        if ( Settings::is_safe_mode() ) {
            return [ 'status' => 'no_provider', 'message' => __( 'Safe Mode is enabled.', 'tmwseo' ) ];
        }

        $name_clean = (string) preg_replace( '/[^A-Za-z0-9]/', '', $model_name );
        $seeds      = [];
        if ( $name_clean !== '' ) {
            $seeds[] = [ 'handle' => $name_clean, 'source_platform' => 'name_derived', 'source_url' => '' ];
        }

        $aliases_raw = trim( (string) get_post_meta( $post_id, \TMWSEO\Engine\Admin\ModelHelper::META_ALIASES, true ) );
        if ( $aliases_raw !== '' ) {
            foreach ( (array) preg_split( '/\s*,\s*/', $aliases_raw ) as $alias ) {
                $alias = trim( (string) $alias );
                if ( $alias !== '' && strtolower( $alias ) !== strtolower( $name_clean ) ) {
                    $seeds[] = [ 'handle' => $alias, 'source_platform' => 'name_derived', 'source_url' => '' ];
                }
                if ( count( $seeds ) >= parent::AUDIT_SEED_CAP ) { break; }
            }
        }

        if ( empty( $seeds ) ) {
            return [
                'status'  => 'partial',
                'message' => __( 'No seeds could be derived for probe-only audit.', 'tmwseo' ),
            ];
        }

        $probe_result = $this->run_full_audit_probe( $seeds, $post_id );
        $verified     = $probe_result['verified_urls'];
        $diagnostics  = $probe_result['diagnostics'];

        $platform_names = [];
        $candidates     = [];
        $seen_slugs     = [];
        foreach ( $verified as $url => $entry ) {
            $slug  = (string) ( $entry['slug'] ?? '' );
            $parse = (array) ( $entry['parse'] ?? [] );
            if ( $slug === '' || empty( $parse['success'] ) ) { continue; }
            $row                        = array_merge( [ 'source_url' => $url ], $parse );
            $row['discovered_via_probe'] = true;
            $candidates[]               = $row;
            if ( ! isset( $seen_slugs[ $slug ] ) ) {
                $seen_slugs[ $slug ] = true;
                $pd = PlatformRegistry::get( $slug );
                $platform_names[] = is_array( $pd )
                    ? (string) ( $pd['name'] ?? ucfirst( $slug ) )
                    : ucfirst( str_replace( '_', ' ', $slug ) );
            }
        }

        $n_confirmed = count( array_filter(
            $diagnostics['platform_coverage'] ?? [],
            static fn( $p ) => ( $p['status'] ?? '' ) === 'confirmed'
        ) );

        $audit_completed  = true; // probe ran to completion (even zero hits)
        $no_matches_found = empty( $verified );

        return [
            'status'               => $no_matches_found ? 'partial' : 'ok',
            'display_name'         => $model_name,
            'aliases'              => [],
            'bio'                  => '',
            'platform_names'       => $platform_names,
            'social_urls'          => [],
            'platform_candidates'  => $candidates,
            'external_candidates'  => [],
            'field_confidence'     => [ 'platform_names' => $n_confirmed > 0 ? 50 : 0, 'social_urls' => 0, 'bio' => 0, 'country' => 0, 'language' => 0, 'source_urls' => 0 ],
            'research_diagnostics' => [
                'query_stats'         => [],
                'platform_probe'      => $diagnostics,
                'platform_coverage'   => $diagnostics['platform_coverage'] ?? [],
                'audit_mode'          => true,
                'audit_completed'     => true,
                'no_matches_found'    => $no_matches_found,
                'confirmed_platforms' => $n_confirmed,
                'audit_config' => [
                    'mode'                  => 'probe_only',
                    'seeds_built'           => count( $seeds ),
                    'probes_attempted'      => (int) ( $diagnostics['probes_attempted'] ?? 0 ),
                    'probes_accepted'       => (int) ( $diagnostics['probes_accepted'] ?? 0 ),
                    'platforms_in_registry' => count( \TMWSEO\Engine\Platform\PlatformRegistry::get_slugs() ),
                    'platforms_checked'     => count( array_filter(
                        $diagnostics['platform_coverage'] ?? [],
                        static fn( $p ) => ( $p['status'] ?? '' ) !== 'not_probed'
                    ) ),
                    'platforms_confirmed'   => $n_confirmed,
                ],
            ],
            'country'    => '',
            'language'   => '',
            'source_urls'=> [],
            'confidence' => min( 90, $n_confirmed * 15 ),
            'notes'      => sprintf(
                $no_matches_found
                    ? 'Probe-only full audit (no SERP): 0 platforms confirmed from %2\$d seed(s). Audit completed — no evidence of this model on any registered platform.'
                    : 'Probe-only full audit (no SERP): %1\$d platform(s) confirmed from %2\$d seed(s).',
                $n_confirmed,
                count( $seeds )
            ),
        ];
    }

    /**
     * Run the full-registry probe phase.
     */
    protected function run_full_audit_probe( array $handle_seeds, int $post_id ): array {
        if ( ! class_exists( ModelPlatformProbe::class ) ) {
            require_once __DIR__ . '/class-model-platform-probe.php';
        }
        return ( new ModelPlatformProbe() )->run_full_audit( $handle_seeds, $post_id );
    }

    /**
     * Run the outbound-link harvest pass.
     *
     * Factored as a protected method so test subclasses can stub the
     * harvester without live HTTP, mirroring the pattern used for
     * run_full_audit_probe().
     *
     * @param  array<int,array{url:string,source_type:string,source_platform?:string}> $seed_pages
     * @param  array<int,array{handle:string,source_platform:string,source_url:string}> $handle_seeds
     * @param  int $post_id
     * @return array{discovered:array,diagnostics:array}
     */
    protected function run_outbound_harvest( array $seed_pages, array $handle_seeds, int $post_id ): array {
        if ( ! class_exists( ModelOutboundHarvester::class ) ) {
            $f = __DIR__ . '/class-model-outbound-harvester.php';
            if ( file_exists( $f ) ) { require_once $f; }
        }
        if ( ! class_exists( ModelOutboundHarvester::class ) ) {
            return [ 'discovered' => [], 'diagnostics' => [] ];
        }
        return ( new ModelOutboundHarvester() )->harvest( $seed_pages, $handle_seeds, $post_id );
    }

    /**
     * Build the seed-page list the outbound harvester will fetch.
     *
     * Sources, in order:
     *   1. Registry-confirmed link-hub pages from the probe phase
     *      (beacons, linktree, allmylinks, solo_to, carrd).
     *   2. Facebook pages surfaced by SERP pass-one whose host is
     *      facebook.com / m.facebook.com / fb.com.
     *
     * Deduplication is applied on normalized URL (lowercased, trailing slash
     * stripped). Personal-website harvesting is currently OFF by default —
     * it would require a classifier to separate personal sites from news,
     * directories, and unrelated pages, and is deferred until that exists.
     *
     * @param  array{verified_urls:array,diagnostics:array} $probe_result
     * @param  array                                        $serp_items_p1
     * @return array<int,array{url:string,source_type:string,source_platform:string}>
     */
    protected function collect_harvest_seed_pages( array $probe_result, array $serp_items_p1 ): array {
        $pages = [];

        // 1. Registry-confirmed link hubs from the probe phase.
        static $link_hub_slugs = [ 'beacons', 'linktree', 'allmylinks', 'solo_to', 'carrd' ];
        $verified_urls = $probe_result['verified_urls'] ?? [];
        if ( is_array( $verified_urls ) ) {
            foreach ( $verified_urls as $url => $entry ) {
                if ( ! is_array( $entry ) ) { continue; }
                $slug = (string) ( $entry['slug'] ?? '' );
                if ( ! in_array( $slug, $link_hub_slugs, true ) ) { continue; }
                $page_url = (string) ( $entry['parse']['normalized_url'] ?? $url );
                if ( $page_url === '' ) { continue; }
                $pages[] = [
                    'url'             => $page_url,
                    'source_type'     => 'linkhub',
                    'source_platform' => $slug,
                ];
            }
        }

        // 2. Facebook pages surfaced by SERP pass-one.
        foreach ( $serp_items_p1 as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $candidate_url = (string) ( $item['url'] ?? $item['link'] ?? '' );
            if ( $candidate_url === '' ) { continue; }
            $host = strtolower( (string) ( parse_url( $candidate_url, PHP_URL_HOST ) ?? '' ) );
            $host = (string) preg_replace( '/^www\./', '', $host );
            if ( $host === '' ) { continue; }
            if (
                $host === 'facebook.com'
                || $host === 'm.facebook.com'
                || $host === 'fb.com'
                || str_ends_with( $host, '.facebook.com' )
            ) {
                $pages[] = [
                    'url'             => $candidate_url,
                    'source_type'     => 'facebook',
                    'source_platform' => 'facebook',
                ];
            }
        }

        // Dedupe by normalized URL.
        $seen = []; $out = [];
        foreach ( $pages as $p ) {
            $k = strtolower( rtrim( (string) ( $p['url'] ?? '' ), '/' ) );
            if ( $k === '' || isset( $seen[ $k ] ) ) { continue; }
            $seen[ $k ] = true;
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Full-audit confirmation pass — checks all PROBE_PRIORITY_SLUGS, not just 3.
     *
     * Runs site:domain.com "handle" queries for every unique seed × platform
     * not already confirmed in pass one. Hard-capped at 20 queries to stay
     * within DataForSEO budget (each costs one API credit).
     *
     * @param  array  $seeds
     * @param  array  $already_confirmed
     * @param  int    $post_id
     * @return array{items:array[],query_stats:array[],confirmation_log:array[]}
     */
    private function run_confirmation_pass_audit(
        array $seeds,
        array $already_confirmed,
        int $post_id
    ): array {
        $all_items   = [];
        $query_stats = [];
        $conf_log    = [];
        $query_count = 0;
        $max_queries = 20;

        // All platforms that have a probeable host get a site: confirmation query.
        $confirmable_slugs = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            if ( isset( $already_confirmed[ $slug ] ) ) { continue; }
            $pd      = PlatformRegistry::get( $slug );
            $pattern = is_array( $pd ) ? (string) ( $pd['profile_url_pattern'] ?? '' ) : '';
            $host    = strtolower( (string) ( parse_url( $pattern, PHP_URL_HOST ) ?? '' ) );
            $host    = (string) preg_replace( '/^www\./', '', $host );
            if ( $host !== '' ) { $confirmable_slugs[ $slug ] = $host; }
        }

        $seeds = array_slice( $seeds, 0, parent::AUDIT_SEED_CAP );

        foreach ( $seeds as $seed ) {
            $handle = (string) ( $seed['handle'] ?? '' );
            if ( $handle === '' ) { continue; }

            foreach ( $confirmable_slugs as $slug => $host ) {
                if ( $query_count >= $max_queries ) { break 2; }
                $query  = 'site:' . $host . ' "' . $handle . '"';
                $result = $this->run_single_confirmation_query_pub( $query, $handle, 'audit_confirmation_' . $slug, $post_id );
                $query_stats[]  = $result['stat'];
                $conf_log[]     = array_merge( [ 'handle' => $handle, 'platform_target' => $slug ], $result['stat'] );
                $all_items      = array_merge( $all_items, $result['items'] );
                $query_count++;
            }
        }

        return [ 'items' => $all_items, 'query_stats' => $query_stats, 'confirmation_log' => $conf_log ];
    }

    /** Public wrapper around the private parent run_single_confirmation_query(). */
    protected function run_single_confirmation_query_pub( string $query, string $handle, string $family, int $post_id ): array {
        return $this->run_single_confirmation_query( $query, $handle, $family, $post_id );
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Register the provider when called explicitly from the full-audit AJAX handler.
     * Does NOT auto-register on tmwseo_research_providers — it only runs when
     * the operator explicitly clicks "Full Audit".
     */
    public static function make(): self {
        if ( ! class_exists( ModelPlatformProbe::class ) ) {
            require_once __DIR__ . '/class-model-platform-probe.php';
        }
        return new self();
    }
}
